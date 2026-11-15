<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Time\EpochMillis;
use PDO;
use PDOStatement;
use Throwable;

/**
 * PostgreSQL outbox table access (plain PDO) — the default OutboxStore implementation.
 * Constructed with the application's connection on the produce side
 * (transaction joining) and with a dedicated connection inside relay processes.
 */
final readonly class PostgreSqlOutboxStore implements OutboxStore
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function insert(OutboxRecord $record): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO outbox_messages
                (id, lane, message_name, body, headers, created_at, available_at, attempts)
            VALUES
                (:id, :lane, :message_name, :body, :headers, :created_at, :available_at, 0)
            SQL);
        $statement->bindValue('id', $record->id);
        $statement->bindValue('lane', $record->lane);
        $statement->bindValue('message_name', $record->messageName);
        self::bindBody($statement, 'body', $record->body); // final wire bytes
        $statement->bindValue('headers', json_encode($record->headers, JSON_THROW_ON_ERROR));
        $statement->bindValue('created_at', self::timestamp($record->createdAt));
        $statement->bindValue('available_at', self::timestamp($record->availableAt ?? $record->createdAt));
        $statement->execute();
    }

    public function drainClaimed(string $lane, int $limit, callable $publish): int
    {
        $this->pdo->beginTransaction();

        try {
            // Oldest-due first is a fairness bias, not an ordering contract. The
            // partial index idx_outbox_pending serves this query; published rows
            // are outside it. FOR UPDATE SKIP LOCKED: concurrent relay workers
            // never block each other.
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT id, lane, message_name, body, headers, created_at, available_at, attempts
                FROM outbox_messages
                WHERE lane = :lane AND published_at IS NULL AND available_at <= :now
                ORDER BY available_at
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
                SQL);
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

            $records = array_map($this->hydrate(...), $rows);
            $outcome = $publish($records);

            // The created_at range of the claim lets PostgreSQL prune the
            // partitions of other days; both writes join the claim transaction.
            $createdAt = array_map(static fn (OutboxRecord $record): int => $record->createdAt, $records);
            $range = [self::timestamp(min($createdAt)), self::timestamp(max($createdAt))];
            if ($outcome->publishedIds !== []) {
                $this->markPublished($outcome->publishedIds, $range);
            }
            if ($outcome->retryAtMs !== []) {
                $this->scheduleRetry($outcome->retryAtMs, $range);
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
     * @param non-empty-list<string> $ids
     * @param array{string, string} $createdAtRange
     */
    private function markPublished(array $ids, array $createdAtRange): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE outbox_messages SET published_at = :published_at'
            .' WHERE id = ANY(CAST(:ids AS uuid[])) AND created_at BETWEEN :from AND :to AND published_at IS NULL',
        );
        $statement->execute([
            'published_at' => self::timestamp(EpochMillis::now()),
            'ids' => self::pgArray($ids),
            'from' => $createdAtRange[0],
            'to' => $createdAtRange[1],
        ]);
    }

    /**
     * Failed publish: move available_at to the retry time, increment attempts.
     * There is no exhaustion and no relay-side DLQ: a row that keeps failing
     * stays in the outbox; retention keeps its partition until the row is
     * published (unless the operator opts in to dropping unpublished rows).
     *
     * @param non-empty-array<string, int> $retryAtMs row id => next available_at, epoch ms
     * @param array{string, string} $createdAtRange
     */
    private function scheduleRetry(array $retryAtMs, array $createdAtRange): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE outbox_messages o SET available_at = r.available_at, attempts = o.attempts + 1'
            .' FROM unnest(CAST(:ids AS uuid[]), CAST(:available_at AS timestamp(3)[])) AS r(id, available_at)'
            .' WHERE o.id = r.id AND o.created_at BETWEEN :from AND :to AND o.published_at IS NULL',
        );
        $statement->execute([
            'ids' => self::pgArray(array_map('strval', array_keys($retryAtMs))),
            'available_at' => self::pgArray(array_map(self::timestamp(...), array_values($retryAtMs))),
            'from' => $createdAtRange[0],
            'to' => $createdAtRange[1],
        ]);
    }

    /**
     * A PostgreSQL array literal. The values are UUIDs and timestamps that
     * this class formats or reads from the database: no quotes, commas or
     * braces inside them, so double quotes per element are enough.
     *
     * @param list<string> $values
     */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $v): string => '"'.$v.'"', $values)).'}';
    }

    private static function bindBody(PDOStatement $statement, string $name, string $body): void
    {
        // BYTEA requires a binary bind; PARAM_LOB makes pdo_pgsql send binary
        // format. JSON text bound this way round-trips through BYTEA unchanged.
        $statement->bindValue($name, $body, PDO::PARAM_LOB);
    }

    private static function readBody(mixed $value): string
    {
        // pdo_pgsql returns BYTEA as a stream resource.
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        if (!is_string($value)) {
            throw new \RuntimeException('Body column is not a string or stream');
        }

        return $value;
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
            body: self::readBody($row['body'] ?? null),
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
