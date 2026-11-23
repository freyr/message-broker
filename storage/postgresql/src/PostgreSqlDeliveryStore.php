<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\ClaimedDelivery;
use Freyr\MessageBroker\Webhook\DeliveryCompletion;
use Freyr\MessageBroker\Webhook\DeliveryCompletionKind;
use Freyr\MessageBroker\Webhook\DeliveryDeadLetter;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use PDO;
use PDOStatement;
use Throwable;

/**
 * PostgreSQL access to the webhook_deliveries table (plain PDO), the
 * implementation of the DeliveryStore port. Constructed with the connection
 * of the application on the produce side (insert() joins its transaction)
 * and with a dedicated connection in the delivery worker.
 *
 * The time of a claim is the database time: now() in UTC, cast once to
 * TIMESTAMP(3). The same value is the due limit (available_at <= it) and the
 * start of the lease that the claim writes. PostgreSQL rounds a value stored
 * in TIMESTAMP(3); a comparison of a rounded stored lease with a raw
 * microsecond now() could find a lease of 0 ms not yet due.
 *
 * dueCounts() is one read statement with no lock. claim() is one statement: in autocommit mode the
 * statement is the transaction, and its commit makes the lease visible before
 * the method returns. The row locks of FOR UPDATE SKIP LOCKED last only for
 * that statement. complete() and abandon() each write in one transaction of
 * the store. No session state: the store works behind a transaction-mode
 * pooler.
 *
 * @phpstan-type Row array{id: string, message_id: string, lane: string, message_name: string, body: resource|string, headers: string, attempts: int, created_at_ms: int, available_at_ms: int}
 */
final readonly class PostgreSqlDeliveryStore implements DeliveryStore
{
    private const int OUTCOME_DELIVERED = 1;
    private const int OUTCOME_DEAD = 2;

    private const string COLUMNS = <<<'SQL'
        w.id, w.message_id, w.lane, w.message_name, w.body, w.headers, w.attempts,
        CAST(EXTRACT(EPOCH FROM w.created_at) * 1000 AS BIGINT) AS created_at_ms,
        CAST(EXTRACT(EPOCH FROM w.available_at) * 1000 AS BIGINT) AS available_at_ms
        SQL;

    /**
     * The lanes come as one JSON array of [lane, row limit] pairs and keep
     * their position in it (WITH ORDINALITY). For each lane a LATERAL
     * subquery starts at the partial index idx_webhook_deliveries_due and
     * takes at most the row limit of that lane in due rows; the total limit
     * then cuts the rows in the order of the lane position. A lane can hold
     * any character, and ->> gives a numeric lane as its text. The statement
     * text is the same for each number of lanes, so a pooler and pdo_pgsql
     * keep one prepared statement.
     *
     * The UPDATE finds the picked rows by their primary key (= ANY of an
     * array), so it reads the primary key index of each partition and not the
     * whole table.
     */
    private const string CLAIM_SQL = <<<'SQL'
        WITH clock AS (
            SELECT CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)) AS now_ms
        ),
        picked AS (
            SELECT d.id, d.created_at
            FROM jsonb_array_elements(CAST(:lanes AS JSONB)) WITH ORDINALITY AS l(lane_limit, position)
            CROSS JOIN LATERAL (
                SELECT c.id, c.created_at
                FROM webhook_deliveries c
                WHERE c.lane = l.lane_limit ->> 0 AND c.completed_at IS NULL AND c.available_at <= (SELECT now_ms FROM clock)
                ORDER BY c.available_at
                LIMIT CAST(l.lane_limit ->> 1 AS INTEGER)
                FOR UPDATE SKIP LOCKED
            ) AS d
            ORDER BY l.position
            LIMIT :total
        )
        UPDATE webhook_deliveries w
        SET available_at = (SELECT now_ms FROM clock) + CAST(:lease_ms AS INTEGER) * INTERVAL '1 millisecond'
        WHERE w.id = ANY(ARRAY(SELECT id FROM picked)) AND w.created_at = ANY(ARRAY(SELECT created_at FROM picked))
        RETURNING
        SQL.' '.self::COLUMNS;

    /**
     * A read with no lock and no write: for each lane of the JSON array, in
     * the order of the array, the due, unfinished rows up to :cap, counted
     * from the partial index idx_webhook_deliveries_due (one index start for
     * each lane). The due limit is the same database time as in CLAIM_SQL.
     */
    private const string DUE_COUNTS_SQL = <<<'SQL'
        WITH clock AS (
            SELECT CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)) AS now_ms
        )
        SELECT l.lane, (
            SELECT COUNT(*) FROM (
                SELECT 1
                FROM webhook_deliveries c
                WHERE c.lane = l.lane AND c.completed_at IS NULL AND c.available_at <= (SELECT now_ms FROM clock)
                LIMIT :cap
            ) AS due
        ) AS due_count
        FROM jsonb_array_elements_text(CAST(:lanes AS JSONB)) WITH ORDINALITY AS l(lane, position)
        ORDER BY l.position
        SQL;

    /**
     * One statement for all outcomes. The lease check is the condition
     * completed_at IS NULL AND available_at = lease; created_at lets
     * PostgreSQL find the partition of each row.
     */
    private const string COMPLETE_SQL = <<<'SQL'
        UPDATE webhook_deliveries w
        SET attempts = o.attempts,
            available_at = COALESCE(o.available_at, w.available_at),
            completed_at = CASE WHEN o.outcome IS NULL THEN NULL ELSE CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)) END,
            outcome = o.outcome
        FROM jsonb_to_recordset(CAST(:outcomes AS JSONB))
            AS o(id UUID, created_at TIMESTAMP(3), lease TIMESTAMP(3), attempts INTEGER, available_at TIMESTAMP(3), outcome SMALLINT)
        WHERE w.id = o.id AND w.created_at = o.created_at AND w.completed_at IS NULL AND w.available_at = o.lease
        RETURNING w.id
        SQL;

    /**
     * The final state dead for at most :limit unfinished rows created before
     * :before, of the lanes of the JSON array :lanes (NULL: all lanes). A row
     * that a different session locks is skipped. A lease does not protect a
     * row, so available_at is not in the condition; the UPDATE keeps it, and
     * the lease check of complete() then skips the row because it is
     * finished. created_at < :before lets PostgreSQL skip the partitions of
     * later days.
     */
    private const string ABANDON_SQL = <<<'SQL'
        WITH picked AS (
            SELECT c.id, c.created_at
            FROM webhook_deliveries c
            WHERE c.completed_at IS NULL AND c.created_at < CAST(:before AS TIMESTAMP(3))
              AND (CAST(:lanes AS JSONB) IS NULL OR c.lane IN (SELECT jsonb_array_elements_text(CAST(:lanes AS JSONB))))
            LIMIT :limit
            FOR UPDATE SKIP LOCKED
        )
        UPDATE webhook_deliveries w
        SET completed_at = CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)),
            outcome =
        SQL.' '.self::OUTCOME_DEAD."\n".<<<'SQL'
        WHERE w.id = ANY(ARRAY(SELECT id FROM picked)) AND w.created_at = ANY(ARRAY(SELECT created_at FROM picked))
          AND w.completed_at IS NULL
        RETURNING
        SQL.' '.self::COLUMNS;

    /**
     * The rows that ABANDON_SQL would take, counted for each lane; COLLATE "C"
     * gives the byte order of the lane names.
     */
    private const string COUNT_UNFINISHED_SQL = <<<'SQL'
        SELECT c.lane, COUNT(*) AS row_count
        FROM webhook_deliveries c
        WHERE c.completed_at IS NULL AND c.created_at < CAST(:before AS TIMESTAMP(3))
          AND (CAST(:lanes AS JSONB) IS NULL OR c.lane IN (SELECT jsonb_array_elements_text(CAST(:lanes AS JSONB))))
        GROUP BY c.lane
        ORDER BY c.lane COLLATE "C"
        SQL;

    public function __construct(
        private PDO $pdo,
    ) {}

    public function insert(array $records): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO webhook_deliveries
                (id, message_id, lane, message_name, body, headers, created_at, available_at, attempts)
            VALUES
                (:id, :message_id, :lane, :message_name, :body, :headers, :created_at, :available_at, :attempts)
            SQL);
        foreach ($records as $record) {
            $statement->bindValue('id', $record->id);
            $statement->bindValue('message_id', $record->messageId);
            $statement->bindValue('lane', $record->lane);
            $statement->bindValue('message_name', $record->messageName);
            // BYTEA requires a binary bind; PARAM_LOB makes pdo_pgsql send binary format.
            $statement->bindValue('body', $record->body, PDO::PARAM_LOB);
            $statement->bindValue('headers', json_encode($record->headers, JSON_THROW_ON_ERROR));
            $statement->bindValue('created_at', self::timestamp($record->createdAt));
            $statement->bindValue('available_at', self::timestamp($record->availableAt ?? $record->createdAt));
            $statement->bindValue('attempts', $record->attempts, PDO::PARAM_INT);
            $statement->execute();
        }
    }

    public function claim(array $limitForEachLane, int $total, int $leaseMs): array
    {
        $laneLimits = [];
        foreach ($limitForEachLane as $lane => $limit) {
            $laneLimits[] = [$lane, $limit];
        }

        $statement = $this->pdo->prepare(self::CLAIM_SQL);
        $statement->bindValue('lanes', json_encode($laneLimits, JSON_THROW_ON_ERROR));
        $statement->bindValue('total', $total, PDO::PARAM_INT);
        $statement->bindValue('lease_ms', $leaseMs, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<Row> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::claimed(...), $rows);
    }

    public function dueCounts(array $lanes, int $cap): array
    {
        $statement = $this->pdo->prepare(self::DUE_COUNTS_SQL);
        $statement->bindValue('lanes', json_encode($lanes, JSON_THROW_ON_ERROR));
        $statement->bindValue('cap', $cap, PDO::PARAM_INT);
        $statement->execute();

        /** @var array<array-key, int> */
        return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function complete(array $outcomes): array
    {
        $first = [];
        foreach ($outcomes as $outcome) {
            // The first outcome of a row wins, as when each outcome ran in turn.
            $first[$outcome->claimed->delivery->id] ??= $outcome;
        }

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(self::COMPLETE_SQL);
            $statement->bindValue('outcomes', json_encode(array_values(array_map(self::outcomeRow(...), $first)), JSON_THROW_ON_ERROR));
            $statement->execute();
            /** @var list<string> $ids */
            $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
            $changed = array_flip($ids);

            $deadLetters = new PostgreSqlDeadLetterStore($this->pdo);
            foreach ($first as $id => $outcome) {
                // Only a dead outcome has a dead letter. The open row rule: one
                // open dead letter for each message ID and source.
                if ($outcome->deadLetter !== null && isset($changed[$id])) {
                    $deadLetters->store($outcome->deadLetter);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollBack();

            throw $error;
        }

        return array_values(array_filter(array_keys($first), static fn (string $id): bool => isset($changed[$id])));
    }

    public function abandon(int $before, ?array $lanes, int $limit): int
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(self::ABANDON_SQL);
            self::bindBeforeAndLanes($statement, $before, $lanes);
            $statement->bindValue('limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            /** @var list<Row> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            $deadLetters = new PostgreSqlDeadLetterStore($this->pdo);
            $failedAt = EpochMillis::now();
            foreach ($rows as $row) {
                // The open row rule: one open dead letter for each message ID and source.
                $deadLetters->store(DeliveryDeadLetter::abandoned(self::claimed($row)->delivery, $before, $failedAt));
            }

            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollBack();

            throw $error;
        }

        return count($rows);
    }

    public function countUnfinished(int $before, ?array $lanes): array
    {
        $statement = $this->pdo->prepare(self::COUNT_UNFINISHED_SQL);
        self::bindBeforeAndLanes($statement, $before, $lanes);
        $statement->execute();

        /** @var array<string, int> */
        return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @param list<string>|null $lanes */
    private static function bindBeforeAndLanes(PDOStatement $statement, int $before, ?array $lanes): void
    {
        $statement->bindValue('before', self::timestamp($before));
        if ($lanes === null) {
            $statement->bindValue('lanes', null, PDO::PARAM_NULL);
        } else {
            // An empty list matches no lane; a lane twice in the list counts one time (IN).
            $statement->bindValue('lanes', json_encode($lanes, JSON_THROW_ON_ERROR));
        }
    }

    /** @return array{id: string, created_at: string, lease: string, attempts: int, available_at: ?string, outcome: ?int} */
    private static function outcomeRow(DeliveryCompletion $outcome): array
    {
        return [
            'id' => $outcome->claimed->delivery->id,
            'created_at' => self::timestamp($outcome->claimed->delivery->createdAt),
            'lease' => self::timestamp($outcome->claimed->lease),
            'attempts' => $outcome->attempts,
            'available_at' => $outcome->availableAt === null ? null : self::timestamp($outcome->availableAt),
            'outcome' => match ($outcome->kind) {
                DeliveryCompletionKind::Delivered => self::OUTCOME_DELIVERED,
                DeliveryCompletionKind::Dead => self::OUTCOME_DEAD,
                DeliveryCompletionKind::Retry => null,
            },
        ];
    }

    /** @param Row $row */
    private static function claimed(array $row): ClaimedDelivery
    {
        $headers = json_decode($row['headers'], true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $headers the column holds the JSON object of the insert */
        $record = new DeliveryRecord(
            id: $row['id'],
            messageId: $row['message_id'],
            lane: $row['lane'],
            messageName: $row['message_name'],
            // pdo_pgsql returns BYTEA as a stream resource.
            body: is_string($row['body']) ? $row['body'] : (string) stream_get_contents($row['body']),
            headers: $headers,
            createdAt: $row['created_at_ms'],
            attempts: $row['attempts'],
            availableAt: $row['available_at_ms'],
        );

        return new ClaimedDelivery($record, $row['available_at_ms']);
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (Throwable) {
                // Dead connection: the transaction died with it. The caller
                // propagates the original failure, not the rollback's.
            }
        }
    }

    private static function timestamp(int $epochMilliseconds): string
    {
        return EpochMillis::toDateTime($epochMilliseconds)->format('Y-m-d H:i:s.v');
    }
}
