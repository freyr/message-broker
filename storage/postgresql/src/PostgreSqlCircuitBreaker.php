<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\LaneState;
use Freyr\MessageBroker\Webhook\LaneStates;
use Freyr\MessageBroker\Webhook\LaneTransitions;
use PDO;
use RuntimeException;

/**
 * The circuit breaker of the webhook lanes in the table webhook_lanes (plain
 * PDO), the implementation of the CircuitBreaker and LaneStates ports. The
 * time is the database time: now() in UTC, cast to TIMESTAMP(3), as in
 * PostgreSqlDeliveryStore.
 *
 * openLanes() is one read of the open and the blocked lanes. record() is one
 * statement in autocommit: it locks the rows of its lanes with SELECT ...
 * ORDER BY lane COLLATE "C" FOR UPDATE (the byte order of the lane IDs, as
 * the in-memory fake), so two workers with the same lanes never wait for each
 * other in a circle, and computes the window, count, trip, block and ignore
 * rules from the locked state. Under the lock, the statement reads the latest
 * state of each row, so it knows which lanes it took out of circulation, which
 * it blocked, and whose first window after open_until it started.
 *
 * A lane that had no result yet has no row. Before the statement, one INSERT
 * ... ON CONFLICT (lane) DO NOTHING in autocommit adds the missing rows,
 * sorted by lane COLLATE "C", with a new window. It commits before any lock
 * of the statement. The store skips it for the lanes that it already saw;
 * that cache is a hint only. When a row is missing at the lock (a different
 * connection deleted it), the statement writes nothing, and the store inserts
 * the rows again and repeats it one time; a second miss throws.
 *
 * Each statement takes the lanes as one jsonb parameter, so its text is the
 * same for each number of lanes. Only transaction-scoped row locks: the store
 * works behind a transaction-mode pooler.
 */
final class PostgreSqlCircuitBreaker implements CircuitBreaker, LaneStates
{
    private const string NOW = "CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3))";

    private const string INSERT_SQL = <<<'SQL'
        INSERT INTO webhook_lanes (lane, window_started_at)
        SELECT v.lane, CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3))
        FROM jsonb_array_elements_text(CAST(:lanes AS JSONB)) AS v(lane)
        ORDER BY v.lane COLLATE "C"
        ON CONFLICT (lane) DO NOTHING
        SQL;

    /**
     * locked: the rows of the lanes, locked in the order of the lane ID; its
     * count is the check that each row exists, and the UPDATE writes nothing
     * when one is missing. started: the lanes that are not blocked and not
     * open, with the start of a new window (the first window after open_until
     * is a return; an older window than the window length starts again, and
     * is a recovery when it had the minimum number of requests). counted: the
     * counts added. judged: the trip and the block.
     */
    private const string RECORD_SQL = <<<'SQL'
        WITH clock AS MATERIALIZED (
            SELECT CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)) AS now_ms
        ),
        input AS MATERIALIZED (
            SELECT c.lane, c.requests, c.failures
            FROM jsonb_to_recordset(CAST(:counts AS JSONB)) AS c(lane TEXT, requests INTEGER, failures INTEGER)
        ),
        locked AS MATERIALIZED (
            SELECT l.lane, l.window_started_at, l.requests, l.failures, l.open_until, l.consecutive_openings, l.blocked_at
            FROM webhook_lanes l
            WHERE l.lane IN (SELECT lane FROM input)
            ORDER BY l.lane COLLATE "C"
            FOR UPDATE
        ),
        started AS (
            SELECT k.lane, c.now_ms, k.window_started_at, k.requests, k.failures, k.consecutive_openings,
                i.requests AS added_requests, i.failures AS added_failures,
                COALESCE(k.window_started_at < k.open_until, FALSE) AS returned,
                c.now_ms - k.window_started_at > CAST(:window_ms AS BIGINT) * INTERVAL '1 millisecond' AS aged
            FROM locked k
            JOIN input i ON i.lane = k.lane
            CROSS JOIN clock c
            WHERE k.blocked_at IS NULL AND COALESCE(k.open_until <= c.now_ms, TRUE)
        ),
        counted AS (
            SELECT s.lane, s.now_ms, s.returned,
                CASE WHEN s.returned OR s.aged THEN s.now_ms ELSE s.window_started_at END AS window_started_at,
                CASE WHEN s.returned OR s.aged THEN 0 ELSE s.requests END + s.added_requests AS requests,
                CASE WHEN s.returned OR s.aged THEN 0 ELSE s.failures END + s.added_failures AS failures,
                CASE WHEN NOT s.returned AND s.aged AND s.requests >= CAST(:minimum_requests AS BIGINT) THEN 0
                    ELSE s.consecutive_openings END AS consecutive_openings
            FROM started s
        ),
        tripped AS (
            SELECT n.*,
                n.requests >= CAST(:minimum_requests AS BIGINT)
                    AND CAST(n.failures AS BIGINT) * 100 >= CAST(:error_rate_percent AS BIGINT) * n.requests AS trips
            FROM counted n
        ),
        judged AS (
            SELECT p.*,
                p.trips AND CAST(:max_openings AS BIGINT) > 0
                    AND p.consecutive_openings >= CAST(:max_openings AS BIGINT) AS blocks
            FROM tripped p
        ),
        written AS (
            UPDATE webhook_lanes t
            SET window_started_at = CASE WHEN j.trips THEN j.now_ms ELSE j.window_started_at END,
                requests = CASE WHEN j.trips THEN 0 ELSE j.requests END,
                failures = CASE WHEN j.trips THEN 0 ELSE j.failures END,
                open_until = CASE WHEN j.trips AND NOT j.blocks
                    THEN j.now_ms + CAST(:open_ms AS BIGINT) * INTERVAL '1 millisecond' ELSE t.open_until END,
                blocked_at = CASE WHEN j.blocks THEN j.now_ms END,
                opened_count = t.opened_count + CASE WHEN j.trips THEN 1 ELSE 0 END,
                consecutive_openings = j.consecutive_openings + CASE WHEN j.trips THEN 1 ELSE 0 END
            FROM judged j
            WHERE t.lane = j.lane AND (SELECT COUNT(*) FROM locked) = CAST(:lane_count AS INTEGER)
        )
        SELECT (SELECT COUNT(*) FROM locked) AS found,
            COALESCE(jsonb_agg(j.lane ORDER BY j.lane COLLATE "C") FILTER (WHERE j.trips AND NOT j.blocks), '[]') AS left_circulation,
            COALESCE(jsonb_agg(j.lane ORDER BY j.lane COLLATE "C") FILTER (WHERE j.returned), '[]') AS returned,
            COALESCE(jsonb_agg(j.lane ORDER BY j.lane COLLATE "C") FILTER (WHERE j.blocks), '[]') AS blocked
        FROM judged j
        SQL;

    private const string ALL_SQL = <<<'SQL'
        SELECT lane,
            CAST(EXTRACT(EPOCH FROM window_started_at) * 1000 AS BIGINT) AS window_started_at,
            requests, failures,
            CAST(EXTRACT(EPOCH FROM open_until) * 1000 AS BIGINT) AS open_until,
            opened_count, consecutive_openings,
            CAST(EXTRACT(EPOCH FROM blocked_at) * 1000 AS BIGINT) AS blocked_at
        FROM webhook_lanes
        ORDER BY lane COLLATE "C"
        SQL;

    /** @var array<array-key, bool> true for each lane whose row this store saw; a hint, a row can be gone */
    private array $known = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly CircuitBreakerPolicy $policy = new CircuitBreakerPolicy(),
    ) {}

    public function openLanes(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT lane FROM webhook_lanes WHERE blocked_at IS NOT NULL OR open_until > '.self::NOW.' ORDER BY lane COLLATE "C"',
        );
        $statement->execute();

        /** @var list<string> */
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function record(array $counts): LaneTransitions
    {
        $lanes = array_map(static fn (LaneCounts $count): string => $count->lane, $counts);
        $this->insert(array_values(array_filter($lanes, fn (string $lane): bool => !($this->known[$lane] ?? false))));
        $transitions = $this->write($counts);
        if ($transitions === null) {
            // A different connection deleted a row after the insert.
            $this->insert($lanes);
            $transitions = $this->write($counts)
                ?? throw new RuntimeException('A row of webhook_lanes is missing after its insert, two times.');
        }
        $this->known += array_fill_keys($lanes, true);

        return $transitions;
    }

    public function policy(): CircuitBreakerPolicy
    {
        return $this->policy;
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare(self::ALL_SQL);
        $statement->execute();
        /** @var list<array{lane: string, window_started_at: int, requests: int, failures: int, open_until: int|null, opened_count: int, consecutive_openings: int, blocked_at: int|null}> $rows pdo_pgsql gives the native types */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): LaneState => new LaneState(
                $row['lane'],
                $row['window_started_at'],
                $row['requests'],
                $row['failures'],
                $row['open_until'],
                $row['opened_count'],
                $row['consecutive_openings'],
                $row['blocked_at'],
            ),
            $rows,
        );
    }

    public function close(string $lane): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_lanes SET window_started_at = '.self::NOW.', requests = 0, failures = 0,'
            .' open_until = NULL, blocked_at = NULL, consecutive_openings = 0 WHERE lane = :lane',
        );
        $statement->execute([
            'lane' => $lane,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param list<LaneCounts> $counts
     *
     * @return LaneTransitions|null null when a row is missing: the statement then wrote nothing
     */
    private function write(array $counts): ?LaneTransitions
    {
        $statement = $this->pdo->prepare(self::RECORD_SQL);
        $statement->execute([
            'counts' => json_encode(array_map(static fn (LaneCounts $count): array => [
                'lane' => $count->lane,
                'requests' => $count->requests,
                'failures' => $count->failures,
            ], $counts), JSON_THROW_ON_ERROR),
            'lane_count' => count($counts),
            'window_ms' => $this->policy->windowMs,
            'minimum_requests' => $this->policy->minimumRequests,
            'error_rate_percent' => $this->policy->errorRatePercent,
            'open_ms' => $this->policy->openMs,
            'max_openings' => $this->policy->maxConsecutiveOpenings,
        ]);
        /** @var array{found: int, left_circulation: string, returned: string, blocked: string} $row one row: the aggregates of the statement */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row['found'] < count($counts)) {
            return null;
        }

        return new LaneTransitions(self::lanes($row['left_circulation']), self::lanes($row['returned']), self::lanes($row['blocked']));
    }

    /** @return list<string> the lanes of a JSON array of the statement */
    private static function lanes(string $json): array
    {
        /** @var list<string> */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $lanes the lanes whose rows to insert when they do not exist */
    private function insert(array $lanes): void
    {
        if ($lanes === []) {
            return;
        }
        $this->pdo->prepare(self::INSERT_SQL)->execute([
            'lanes' => json_encode($lanes, JSON_THROW_ON_ERROR),
        ]);
    }
}
