<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlCircuitBreaker;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlRateLimiter;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimits;
use PDO;

/**
 * Two sessions that use the same lanes in opposite orders at the same time
 * get no deadlock error: the rate limiter and the circuit breaker lock and
 * insert their rows in the order of the name (COLLATE "C"). A deadlock error
 * in a session fails the test.
 *
 * Only the child processes run the stores in the long tests, so their
 * coverage is not recorded and Infection does not run them for a mutant.
 */
final class FlowControlLockOrderTest extends FunctionalTestCase
{
    private const int CALLS = 1_000;
    private const array LANES = ['lane_a', 'lane_b', 'lane_c', 'lane_d'];

    public function testTwoSessionsAcquireWithOppositeLaneOrdersOnRowsThatExist(): void
    {
        $limits = self::rateLimits(self::LANES);
        // SQL, not the store: only the sessions run the store, so Infection does not pick this long test.
        self::$pdo->exec("INSERT INTO webhook_rate_limit (name, tokens, refilled_at) SELECT name, 100000, now() FROM unnest(ARRAY['lane_a', 'lane_b', 'lane_c', 'lane_d']) AS name");

        $acquire = static fn (array $lanes): \Closure => static function (PDO $pdo) use ($lanes, $limits): int {
            $limiter = new PostgreSqlRateLimiter($pdo);
            for ($call = 0; $call < self::CALLS; ++$call) {
                $limiter->acquire(array_fill_keys($lanes, 1), 100, $limits);
            }

            return self::CALLS;
        };

        self::assertSame([self::CALLS, self::CALLS], ConcurrentSessions::run(self::newConnection(...), [
            $acquire(self::LANES),
            $acquire(array_reverse(self::LANES)),
        ]));
    }

    public function testTwoSessionsAcquireWithOppositeLaneOrdersAndInsertTheNewRows(): void
    {
        $acquire = static fn (bool $reversed): \Closure => static function (PDO $pdo) use ($reversed): int {
            $limiter = new PostgreSqlRateLimiter($pdo);
            for ($call = 0; $call < self::CALLS; ++$call) {
                $lanes = array_map(static fn (string $lane): string => "{$lane}_{$call}", self::LANES);
                $lanes = $reversed ? array_reverse($lanes) : $lanes;
                $limiter->acquire(array_fill_keys($lanes, 1), 100, self::rateLimits($lanes));
            }

            return self::CALLS;
        };

        self::assertSame([self::CALLS, self::CALLS], ConcurrentSessions::run(self::newConnection(...), [$acquire(false), $acquire(true)]));
        self::assertSame(4 * self::CALLS, self::fetchInt('SELECT COUNT(*) FROM webhook_rate_limit'), 'one row for each new lane');
    }

    public function testTwoSessionsRecordWithOppositeLaneOrdersOnRowsThatExist(): void
    {
        $policy = new CircuitBreakerPolicy(minimumRequests: 1_000_000);
        self::$pdo->exec("INSERT INTO webhook_lanes (lane, window_started_at, requests) SELECT lane, now() AT TIME ZONE 'UTC', 1 FROM unnest(ARRAY['lane_a', 'lane_b', 'lane_c', 'lane_d']) AS lane");

        $record = static fn (array $lanes): \Closure => static function (PDO $pdo) use ($lanes, $policy): int {
            $breaker = new PostgreSqlCircuitBreaker($pdo, $policy);
            for ($call = 0; $call < self::CALLS; ++$call) {
                $breaker->record(self::counts($lanes));
            }

            return self::CALLS;
        };

        self::assertSame([self::CALLS, self::CALLS], ConcurrentSessions::run(self::newConnection(...), [
            $record(self::LANES),
            $record(array_reverse(self::LANES)),
        ]));
        self::assertSame(4 * (1 + 2 * self::CALLS), self::fetchInt('SELECT SUM(requests) FROM webhook_lanes'), 'each call counts');
    }

    public function testTwoSessionsRecordWithOppositeLaneOrdersAndInsertTheNewRows(): void
    {
        $record = static fn (bool $reversed): \Closure => static function (PDO $pdo) use ($reversed): int {
            $breaker = new PostgreSqlCircuitBreaker($pdo);
            for ($call = 0; $call < self::CALLS; ++$call) {
                $lanes = array_map(static fn (string $lane): string => "{$lane}_{$call}", self::LANES);
                $breaker->record(self::counts($reversed ? array_reverse($lanes) : $lanes));
            }

            return self::CALLS;
        };

        self::assertSame([self::CALLS, self::CALLS], ConcurrentSessions::run(self::newConnection(...), [$record(false), $record(true)]));
        self::assertSame(4 * self::CALLS, self::fetchInt('SELECT COUNT(*) FROM webhook_lanes'));
        self::assertSame(2 * 4 * self::CALLS, self::fetchInt('SELECT SUM(requests) FROM webhook_lanes'), 'each call counts');
    }

    public function testTheRateLimiterInsertsARowAgainThatADifferentConnectionDeleted(): void
    {
        $limiter = new PostgreSqlRateLimiter(self::$pdo);
        $limits = self::rateLimits(['crm', 'erp']);
        $limiter->acquire([
            'crm' => 1,
            'erp' => 1,
        ], 100, $limits);
        self::newConnection()->exec("DELETE FROM webhook_rate_limit WHERE name = 'erp'");

        self::assertSame([
            'crm' => 2,
            'erp' => 2,
        ], $limiter->acquire([
            'crm' => 2,
            'erp' => 2,
        ], 100, $limits));
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM webhook_rate_limit'));
    }

    /**
     * No global rate: the global row is the first lock of each call and would
     * serialize the calls, so only the lane rows show the lock order.
     *
     * @param list<string> $lanes
     */
    private static function rateLimits(array $lanes): RateLimits
    {
        return new RateLimits(null, array_fill_keys($lanes, new Rate(100_000, 100_000)));
    }

    /**
     * @param list<string> $lanes
     *
     * @return list<LaneCounts>
     */
    private static function counts(array $lanes): array
    {
        return array_map(static fn (string $lane): LaneCounts => new LaneCounts($lane, 1, 0), $lanes);
    }
}
