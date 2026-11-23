<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use Freyr\MessageBroker\Outbox\FailingRow;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * PostgreSQL outbox table access (plain PDO) — the default OutboxStore implementation.
 * Constructed with the application's connection on the produce side
 * (transaction joining) and with a dedicated connection inside relay processes.
 * $publishedRows selects what the drain does with a published row: mark it
 * (published_at, the default) or delete it ({@see PublishedRows}).
 */
final readonly class PostgreSqlOutboxStore implements OutboxStore
{
    public function __construct(
        private PDO $pdo,
        private PublishedRows $publishedRows = PublishedRows::Mark,
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
            // are outside it, abandoned rows are inside it and skipped here.
            // FOR UPDATE SKIP LOCKED: concurrent relay workers never block
            // each other.
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT id, lane, message_name, body, headers, created_at, available_at, attempts
                FROM outbox_messages
                WHERE lane = :lane AND published_at IS NULL AND abandoned_at IS NULL AND available_at <= :now
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
                match ($this->publishedRows) {
                    PublishedRows::Mark => $this->markPublished($outcome->publishedIds, $range),
                    PublishedRows::Delete => $this->deletePublished($outcome->publishedIds, $range),
                };
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
     * One UPDATE with no created_at range: it reads the primary key index of
     * each partition, an acceptable cost for an operator command. A value that
     * is not a UUID matches no row: it returns 0 and sends no query.
     */
    public function abandon(string $id): int
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $id) !== 1) {
            return 0;
        }
        $statement = $this->pdo->prepare(
            'UPDATE outbox_messages SET abandoned_at = :now'
            .' WHERE id = :id AND published_at IS NULL AND abandoned_at IS NULL',
        );
        $statement->execute([
            'now' => self::timestamp(EpochMillis::now()),
            'id' => $id,
        ]);

        return $statement->rowCount();
    }

    public function failing(string $lane, int $minAttempts, int $limit): array
    {
        if ($minAttempts < 1 || $limit < 1) {
            throw new InvalidArgumentException("minAttempts and limit must be 1 or more, got {$minAttempts} and {$limit}");
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, lane, message_name, attempts, created_at, available_at, octet_length(body) AS body_bytes
            FROM outbox_messages
            WHERE lane = :lane AND published_at IS NULL AND abandoned_at IS NULL AND attempts >= :min
            ORDER BY attempts DESC, created_at
            LIMIT :limit
            SQL);
        $statement->bindValue('lane', $lane);
        $statement->bindValue('min', $minAttempts, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): FailingRow => new FailingRow(
            id: self::stringColumn($row, 'id'),
            lane: self::stringColumn($row, 'lane'),
            messageName: self::stringColumn($row, 'message_name'),
            attempts: self::intColumn($row, 'attempts'),
            createdAt: self::epochMilliseconds(self::stringColumn($row, 'created_at')),
            availableAt: self::epochMilliseconds(self::stringColumn($row, 'available_at')),
            bodyBytes: self::intColumn($row, 'body_bytes'),
        ), $rows);
    }

    /**
     * Published rows stay for retention; published_at is the audit trail and
     * keeps them out of every later claim. The `published_at IS NULL` guard
     * matters on replay: a replayed message reuses its original id, and the
     * original row (already published) must keep its own published_at. The
     * `abandoned_at IS NULL` guard is a second barrier: the claim locks keep an
     * abandon away from a claimed row.
     *
     * @param non-empty-list<string> $ids
     * @param array{string, string} $createdAtRange
     */
    private function markPublished(array $ids, array $createdAtRange): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE outbox_messages SET published_at = :published_at'
            .' WHERE id = ANY(CAST(:ids AS uuid[])) AND created_at BETWEEN :from AND :to AND published_at IS NULL AND abandoned_at IS NULL',
        );
        $statement->execute([
            'published_at' => self::timestamp(EpochMillis::now()),
            'ids' => self::pgArray($ids),
            'from' => $createdAtRange[0],
            'to' => $createdAtRange[1],
        ]);
    }

    /**
     * PublishedRows::Delete: the published rows leave the table in the claim
     * transaction. The guards are those of markPublished(): an older row with
     * the same id (a replay of a message that a Mark store published) and a
     * row that an operator abandoned meanwhile stay.
     *
     * @param non-empty-list<string> $ids
     * @param array{string, string} $createdAtRange
     */
    private function deletePublished(array $ids, array $createdAtRange): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM outbox_messages'
            .' WHERE id = ANY(CAST(:ids AS uuid[])) AND created_at BETWEEN :from AND :to AND published_at IS NULL AND abandoned_at IS NULL',
        );
        $statement->execute([
            'ids' => self::pgArray($ids),
            'from' => $createdAtRange[0],
            'to' => $createdAtRange[1],
        ]);
    }

    /**
     * Failed publish: move available_at to the retry time, increment attempts.
     * There is no exhaustion and no relay-side DLQ: a row that keeps failing
     * stays in the outbox; retention keeps its partition until the row is
     * published (unless the operator opts in to dropping unpublished rows), or
     * until an operator abandons it. An abandoned row is not changed.
     *
     * @param non-empty-array<string, int> $retryAtMs row id => next available_at, epoch ms
     * @param array{string, string} $createdAtRange
     */
    private function scheduleRetry(array $retryAtMs, array $createdAtRange): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE outbox_messages o SET available_at = r.available_at, attempts = o.attempts + 1'
            .' FROM unnest(CAST(:ids AS uuid[]), CAST(:available_at AS timestamp(3)[])) AS r(id, available_at)'
            .' WHERE o.id = r.id AND o.created_at BETWEEN :from AND :to AND o.published_at IS NULL AND o.abandoned_at IS NULL',
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
