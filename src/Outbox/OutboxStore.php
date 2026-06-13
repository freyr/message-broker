<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;

/**
 * PDO-backed outbox table access. Constructed with the application's
 * connection on the produce side (transaction joining) and with a dedicated
 * connection inside relay processes.
 */
final readonly class OutboxStore
{
    public function __construct(
        private PDO $pdo,
        private Platform $platform,
    ) {}

    public function insert(OutboxRecord $record): void
    {
        $statement = $this->pdo->prepare($this->platform->insertOutboxSql());
        $statement->execute([
            'id' => $record->id,
            'lane' => $record->lane,
            'message_key' => $record->key,
            'message_name' => $record->messageName(), // mirrors metadata.message_name; lets Debezium map it to x-message-name via stock EventRouter
            'metadata' => json_encode($record->metadata, JSON_THROW_ON_ERROR),
            'body' => $record->body, // final wire bytes (JSON text or Confluent-framed Avro)
            'headers' => json_encode($record->headers, JSON_THROW_ON_ERROR),
            'created_at' => EpochMillis::toDateTime($record->createdAt)->format('Y-m-d H:i:s.v'),
            'available_at' => EpochMillis::toDateTime($record->availableAt ?? $record->createdAt)->format(
                'Y-m-d H:i:s.v',
            ),
        ]);
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

        return (int) $statement->fetchColumn() === 1;
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

        return array_map(self::hydrate(...), $rows);
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
    private static function hydrate(array $row): OutboxRecord
    {
        return new OutboxRecord(
            id: self::stringColumn($row, 'id'),
            lane: self::stringColumn($row, 'lane'),
            key: self::stringColumn($row, 'message_key'),
            metadata: self::jsonColumn($row, 'metadata'),
            body: self::stringColumn($row, 'body'),
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

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function epochMilliseconds(string $storedDateTime): int
    {
        $dateTime = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.v',
            $storedDateTime,
            new \DateTimeZone('UTC'),
        );

        if ($dateTime === false) {
            throw new \RuntimeException("Unparseable stored timestamp: {$storedDateTime}");
        }

        return EpochMillis::fromDateTime($dateTime);
    }
}
