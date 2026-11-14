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
        $statement->bindValue('message_name', $record->messageName);
        $this->platform->bindBody($statement, 'body', $record->body); // final wire bytes (JSON text or Avro)
        $statement->bindValue('headers', json_encode($record->headers, JSON_THROW_ON_ERROR));
        $statement->bindValue('created_at', self::timestamp($record->createdAt));
        $statement->bindValue('available_at', self::timestamp($record->availableAt ?? $record->createdAt));
        $statement->execute();
    }

    public function drainClaimed(string $lane, int $limit, callable $publish): int
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare($this->platform->selectClaimBatchSql());
            $statement->bindValue('lane', $lane);
            $statement->bindValue('now', self::timestamp(EpochMillis::now()));
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
                $this->markPublished($outcome->publishedIds); // joins the open claim transaction
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

    /**
     * Published rows stay for retention; published_at is the audit trail and
     * keeps them out of every later claim. The `published_at IS NULL` guard
     * matters on replay: a replayed message reuses its original id, and the
     * original row (already published) must keep its own published_at.
     *
     * @param list<string> $ids
     * @param positive-int $chunkSize
     */
    private function markPublished(array $ids, int $chunkSize = 500): void
    {
        $publishedAt = self::timestamp(EpochMillis::now());
        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                "UPDATE outbox_messages SET published_at = ? WHERE published_at IS NULL AND id IN ({$placeholders})",
            );
            $statement->execute([$publishedAt, ...$chunk]);
        }
    }

    /**
     * Transient publish failure: bump available_at, increment attempts. There
     * is no exhaustion and no relay-side DLQ; a row that keeps failing is an
     * operational alert (and it blocks retention of its partition).
     */
    private function scheduleRetry(string $id, int $availableAtMs): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE outbox_messages SET available_at = :available_at, attempts = attempts + 1'
            .' WHERE published_at IS NULL AND id = :id',
        );
        $statement->execute([
            'available_at' => self::timestamp($availableAtMs),
            'id' => $id,
        ]);
    }

    private static function timestamp(int $epochMilliseconds): string
    {
        return EpochMillis::toDateTime($epochMilliseconds)->format('Y-m-d H:i:s.v');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): OutboxRecord
    {
        return new OutboxRecord(
            id: self::stringColumn($row, 'id'),
            lane: self::stringColumn($row, 'lane'),
            messageName: self::stringColumn($row, 'message_name'),
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
