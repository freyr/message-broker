<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;
use Throwable;

/**
 * PDO-backed outbox table access — the default OutboxStore implementation.
 * Constructed with the application's connection on the produce side
 * (transaction joining) and with a dedicated connection inside relay processes.
 */
final readonly class PdoOutboxStore implements OutboxStore
{
    public function __construct(
        private PDO $pdo,
        private Platform $platform,
    ) {}

    public function insert(OutboxRecord $record): void
    {
        $statement = $this->pdo->prepare($this->platform->insertOutboxSql());
        $statement->bindValue('id', $record->id);
        $statement->bindValue('lane', $record->lane);
        $statement->bindValue('message_key', $record->key);
        // mirrors metadata.message_name; lets Debezium map it to x-message-name via stock EventRouter
        $statement->bindValue('message_name', $record->messageName());
        $statement->bindValue('metadata', json_encode($record->metadata, JSON_THROW_ON_ERROR));
        $this->platform->bindBody($statement, 'body', $record->body); // final wire bytes (JSON text or Avro)
        $statement->bindValue('headers', json_encode($record->headers, JSON_THROW_ON_ERROR));
        $statement->bindValue('created_at', EpochMillis::toDateTime($record->createdAt)->format('Y-m-d H:i:s.v'));
        $statement->bindValue(
            'available_at',
            EpochMillis::toDateTime($record->availableAt ?? $record->createdAt)->format('Y-m-d H:i:s.v'),
        );
        $statement->execute();
    }

    /**
     * Exclusive, session-scoped ownership of one lane via advisory lock.
     * Self-releases if this connection dies — crash recovery for free.
     * One relay per lane = total in-order publishing per lane.
     */
    public function tryAcquireLane(string $lane): bool
    {
        $statement = $this->pdo->prepare($this->platform->tryAcquireLaneSql());
        $statement->execute([
            'lane' => $lane,
        ]);

        $result = $statement->fetchColumn();

        // MySQL GET_LOCK → 1/'1'; PG pg_try_advisory_lock → true (PHP bool) or 't'.
        // PDO type map differs: pdo_pgsql returns PHP bool; pdo_mysql returns int/string.
        // @phpstan-ignore identical.alwaysFalse (pdo_pgsql returns PHP bool despite PDOStatement::fetchColumn() signature)
        return $result === 1 || $result === '1' || $result === true || $result === 't';
    }

    /** Release the lane lock so a restarting/standby relay can take over. */
    public function releaseLane(string $lane): void
    {
        $statement = $this->pdo->prepare($this->platform->releaseLaneSql());
        $statement->execute([
            'lane' => $lane,
        ]);
    }

    /**
     * Contiguous prefix of one OWNED lane, ordered by id (UUIDv7 = time).
     * Caller checks head eligibility (available_at) on the first row only —
     * a backing-off head blocks the whole lane; nothing overtakes (D17).
     *
     * @return list<OutboxRecord>
     */
    public function lanePrefix(string $lane, int $limit): array
    {
        $statement = $this->pdo->prepare($this->platform->selectLanePrefixSql());
        $statement->bindValue('lane', $lane);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function drainClaimed(string $lane, int $limit, callable $publish): int
    {
        $isolation = $this->platform->claimIsolationSql();
        if ($isolation !== null) {
            $this->pdo->exec($isolation); // next transaction only
        }
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare($this->platform->selectClaimBatchSql());
            $statement->bindValue('lane', $lane);
            $statement->bindValue('now', EpochMillis::toDateTime(EpochMillis::now())->format('Y-m-d H:i:s.v'));
            $statement->bindValue('limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                $this->pdo->commit();

                return 0;
            }

            $outcome = $publish(array_map($this->hydrate(...), $rows));

            if ($outcome->publishedIds !== []) {
                $this->deleteBatch($outcome->publishedIds); // joins the open claim transaction
            }
            foreach ($outcome->retryAtMs as $id => $availableAtMs) {
                $this->scheduleRetry($id, $availableAtMs);
            }

            $this->pdo->commit();

            return count($outcome->publishedIds);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                    // Dead connection: its claim locks died with it. Propagate
                    // the original failure, not the rollback's.
                }
            }

            throw $error;
        }
    }

    /** Successful publish — the row's job is done. Rows leave ONLY this way. */
    public function delete(string $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM outbox_messages WHERE id = :id');
        $statement->execute([
            'id' => $id,
        ]);
    }

    /**
     * Batched variant for the relay's batched drain.
     *
     * @param list<string> $ids
     * @param positive-int $chunkSize
     */
    public function deleteBatch(array $ids, int $chunkSize = 500): void
    {
        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare("DELETE FROM outbox_messages WHERE id IN ({$placeholders})");
            $statement->execute($chunk);
        }
    }

    /**
     * Transient publish failure: bump the head's available_at, increment
     * attempts. The lane waits out the backoff — there is no exhaustion,
     * no relay-side DLQ; a long-blocked lane is an operational alert.
     */
    public function scheduleRetry(string $id, int $availableAtMs): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE outbox_messages SET available_at = :available_at, attempts = attempts + 1 WHERE id = :id',
        );
        $statement->execute([
            'available_at' => EpochMillis::toDateTime($availableAtMs)->format('Y-m-d H:i:s.v'),
            'id' => $id,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): OutboxRecord
    {
        return new OutboxRecord(
            id: self::stringColumn($row, 'id'),
            lane: self::stringColumn($row, 'lane'),
            key: self::stringColumn($row, 'message_key'),
            metadata: self::jsonColumn($row, 'metadata'),
            body: $this->platform->readBody($row['body'] ?? null),
            headers: self::jsonColumn($row, 'headers'),
            createdAt: self::epochMilliseconds(self::stringColumn($row, 'created_at')),
            attempts: self::intColumn($row, 'attempts'),
            availableAt: self::epochMilliseconds(self::stringColumn($row, 'available_at')),
        );
    }

    /** @param array<string, mixed> $row */
    private static function stringColumn(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException("Outbox column '{$column}' is not a string");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function intColumn(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (!is_int($value) && !(is_string($value) && is_numeric($value))) {
            throw new \RuntimeException("Outbox column '{$column}' is not an integer");
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function jsonColumn(array $row, string $column): array
    {
        $decoded = json_decode(self::stringColumn($row, $column), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Outbox column '{$column}' does not hold a JSON object");
        }

        /** @var array<string, mixed> $typed */
        $typed = $decoded;

        return $typed;
    }

    private static function epochMilliseconds(string $storedDateTime): int
    {
        // The DateTimeImmutable constructor accepts variable fractional-second
        // digits (PG trims trailing zeros, e.g. '...56.7'); createFromFormat
        // with a fixed 'v' mask does not.
        try {
            $dateTime = new \DateTimeImmutable($storedDateTime, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new \RuntimeException("Unparseable stored timestamp: {$storedDateTime}");
        }

        return EpochMillis::fromDateTime($dateTime);
    }
}
