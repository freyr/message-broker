<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlRateLimiter;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimits;
use PDO;
use PDOException;
use RuntimeException;

/** The PostgreSQL rules of the rate limiter: the rows, the name cache, the lock, the time and the bounds under load. */
final class PostgreSqlRateLimiterTest extends FunctionalTestCase
{
    protected function tearDown(): void
    {
        self::$pdo->exec('DROP TRIGGER IF EXISTS rate_limit_test_trigger ON webhook_rate_limit');
        self::$pdo->exec('DROP FUNCTION IF EXISTS rate_limit_test_trigger()');
    }

    public function testANewRowStartsWithTheBurstOfItsRateAndTheCallSubtractsItsGrants(): void
    {
        $limiter = new PostgreSqlRateLimiter(self::$pdo);

        $limiter->acquire([
            'crm' => 3,
            'erp' => 1,
        ], 100, new RateLimits(new Rate(1, 10), [
            'crm' => new Rate(1, 5),
        ]));

        self::assertSame([
            '' => 6.0,
            'crm' => 2.0,
        ], $this->tokens(), 'no row for the lane with no limit');
    }

    public function testWithNoGlobalRateTheGlobalRowIsNeitherInsertedNorLockedNorWritten(): void
    {
        self::$pdo->exec("INSERT INTO webhook_rate_limit (name, tokens, refilled_at) VALUES ('', 0.5, '2020-01-01T00:00:00Z')");
        $holder = self::newConnection();
        $holder->beginTransaction();
        $holder->exec("SELECT 1 FROM webhook_rate_limit WHERE name = '' FOR UPDATE");
        $store = self::newConnection();
        $store->exec("SET statement_timeout = '2s'");

        $grants = (new PostgreSqlRateLimiter($store))->acquire([
            'crm' => 9,
        ], 100, new RateLimits(null, [
            'crm' => new Rate(1, 4),
        ]));
        $holder->rollBack();

        self::assertSame([
            'crm' => 4,
        ], $grants);
        self::assertSame([
            '' => 0.5,
            'crm' => 0.0,
        ], $this->tokens());
        self::assertSame('2020-01-01 00:00:00+00', self::fetchString("SELECT refilled_at FROM webhook_rate_limit WHERE name = ''"));
    }

    public function testAGiveBackWithNoGlobalRateLeavesTheGlobalRow(): void
    {
        self::$pdo->exec("INSERT INTO webhook_rate_limit (name, tokens, refilled_at) VALUES ('', 0.5, '2020-01-01T00:00:00Z')");
        $limiter = new PostgreSqlRateLimiter(self::$pdo);
        $limits = new RateLimits(null, [
            'crm' => new Rate(1, 4),
        ]);
        $limiter->acquire([
            'crm' => 4,
        ], 100, $limits);

        $limiter->giveBack([
            'crm' => 3,
        ], $limits);

        self::assertEqualsWithDelta([
            '' => 0.5,
            'crm' => 3.0,
        ], $this->tokens(), 0.1, 'the second call refills a few milliseconds');
    }

    public function testAGiveBackFillsEachRowToItsBurstCapacityAndNoMore(): void
    {
        $limiter = new PostgreSqlRateLimiter(self::$pdo);
        $limits = new RateLimits(new Rate(1, 10), [
            'crm' => new Rate(1, 4),
        ]);
        $limiter->acquire([
            'crm' => 1,
            'erp' => 1,
        ], 100, $limits);

        $limiter->giveBack([
            'crm' => 100,
            'erp' => 100,
        ], $limits);

        self::assertSame([
            '' => 10.0,
            'crm' => 4.0,
        ], $this->tokens());
    }

    public function testACallWithNoBucketWritesNoRow(): void
    {
        $limiter = new PostgreSqlRateLimiter(self::$pdo);

        self::assertSame([
            'crm' => 3,
            'erp' => 2,
        ], $limiter->acquire([
            'crm' => 3,
            'erp' => 3,
        ], 5, new RateLimits(null)));
        $limiter->giveBack([
            'crm' => 1,
        ], new RateLimits(null));

        self::assertSame([], $this->tokens());
    }

    public function testADatabaseTimeEarlierThanTheStoredTimeGivesNoRefillAndKeepsTheStoredTime(): void
    {
        self::$pdo->exec("INSERT INTO webhook_rate_limit (name, tokens, refilled_at) VALUES ('crm', 0.5, date_trunc('milliseconds', now() + INTERVAL '10 seconds'))");
        $stored = self::fetchString("SELECT refilled_at FROM webhook_rate_limit WHERE name = 'crm'");

        $grants = (new PostgreSqlRateLimiter(self::$pdo))->acquire([
            'crm' => 5,
        ], 100, new RateLimits(null, [
            'crm' => new Rate(1, 5),
        ]));

        self::assertSame([
            'crm' => 0,
        ], $grants);
        self::assertSame([
            'crm' => 0.5,
        ], $this->tokens());
        self::assertSame($stored, self::fetchString("SELECT refilled_at FROM webhook_rate_limit WHERE name = 'crm'"), 'the stored time does not move back');
    }

    public function testTheStoreSkipsTheInsertForTheNamesThatItAlreadySaw(): void
    {
        $limits = new RateLimits(new Rate(1, 10), [
            'crm' => new Rate(1, 5),
            'erp' => new Rate(1, 5),
        ]);
        $limiter = new PostgreSqlRateLimiter(self::$pdo);
        $limiter->acquire([
            'crm' => 1,
        ], 100, $limits);
        $limiter->acquire([
            'erp' => 1,
        ], 100, $limits);
        $this->createTrigger('BEFORE INSERT', 'STATEMENT', "RAISE EXCEPTION 'insert into webhook_rate_limit'");

        self::assertSame([
            'crm' => 1,
        ], $limiter->acquire([
            'crm' => 1,
        ], 100, $limits), 'no INSERT statement: the store keeps the names of each earlier call');
        self::assertSame([
            'erp' => 1,
        ], $limiter->acquire([
            'erp' => 1,
        ], 100, $limits));
        $limiter->giveBack([
            'crm' => 1,
        ], $limits);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('insert into webhook_rate_limit');
        (new PostgreSqlRateLimiter(self::$pdo))->acquire([
            'crm' => 1,
        ], 100, $limits);
    }

    public function testTheStoreInsertsTheRowOfANameThatItDidNotSeeYet(): void
    {
        $limiter = new PostgreSqlRateLimiter(self::$pdo);
        $limiter->acquire([
            'crm' => 1,
        ], 100, new RateLimits(null, [
            'crm' => new Rate(1, 5),
        ]));

        $limiter->acquire([
            'crm' => 1,
            'erp' => 2,
        ], 100, new RateLimits(new Rate(1, 10), [
            'crm' => new Rate(1, 5),
            'erp' => new Rate(1, 3),
        ]));

        self::assertEqualsWithDelta([
            '' => 7.0,
            'crm' => 3.0,
            'erp' => 1.0,
        ], $this->tokens(), 0.1, 'the second call refills a few milliseconds');
    }

    public function testARowThatIsStillMissingAfterTheSecondInsertThrows(): void
    {
        $this->createTrigger('BEFORE INSERT', 'ROW', 'RETURN NULL');

        try {
            (new PostgreSqlRateLimiter(self::$pdo))->acquire([
                'crm' => 1,
            ], 100, new RateLimits(null, [
                'crm' => new Rate(1, 5),
            ]));
            self::fail('No exception');
        } catch (RuntimeException $e) {
            self::assertSame('A row of webhook_rate_limit is missing after its insert, two times.', $e->getMessage());
        }
        self::assertFalse(self::$pdo->inTransaction());
    }

    public function testAnErrorInTheTransactionRollsItBack(): void
    {
        $limiter = new PostgreSqlRateLimiter(self::$pdo);
        $limits = new RateLimits(new Rate(1, 10));
        $limiter->acquire([
            'crm' => 1,
        ], 100, $limits);
        $this->createTrigger('BEFORE UPDATE', 'ROW', "RAISE EXCEPTION 'update of webhook_rate_limit'");

        try {
            $limiter->acquire([
                'crm' => 1,
            ], 100, $limits);
            self::fail('No exception');
        } catch (PDOException $e) {
            self::assertStringContainsString('update of webhook_rate_limit', $e->getMessage());
        }
        self::assertFalse(self::$pdo->inTransaction());
        self::assertSame([
            '' => 9.0,
        ], $this->tokens());
    }

    public function testAnErrorAtTheCommitPropagatesUnchanged(): void
    {
        $limiter = new PostgreSqlRateLimiter(self::$pdo);
        $limits = new RateLimits(new Rate(1, 10));
        $limiter->acquire([
            'crm' => 1,
        ], 100, $limits);
        self::$pdo->exec("CREATE FUNCTION rate_limit_test_trigger() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'commit of webhook_rate_limit'; END \$\$");
        self::$pdo->exec('CREATE CONSTRAINT TRIGGER rate_limit_test_trigger AFTER UPDATE ON webhook_rate_limit DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION rate_limit_test_trigger()');

        try {
            $limiter->acquire([
                'crm' => 1,
            ], 100, $limits);
            self::fail('No exception');
        } catch (PDOException $e) {
            self::assertStringContainsString('commit of webhook_rate_limit', $e->getMessage());
        }
        self::assertFalse(self::$pdo->inTransaction());
    }

    public function testTheUpdatesOfTheRowsAreHotUpdates(): void
    {
        [$updatesBefore, $hotBefore] = $this->updateCounts();
        $connection = self::newConnection();
        $limiter = new PostgreSqlRateLimiter($connection);
        $limits = new RateLimits(new Rate(1000, 1000), [
            'crm' => new Rate(1000, 1000),
        ]);
        for ($call = 0; $call < 50; ++$call) {
            $limiter->acquire([
                'crm' => 1,
                'erp' => 1,
            ], 100, $limits);
        }
        unset($limiter, $connection); // the backend sends its statistics when it ends

        $deadline = hrtime(true) + 5_000_000_000;
        do {
            usleep(20_000);
            [$updates, $hot] = $this->updateCounts();
        } while ($updates - $updatesBefore < 100 && hrtime(true) < $deadline);

        // The statistics of an earlier test can arrive in the same time.
        self::assertGreaterThanOrEqual(100, $updates - $updatesBefore, 'two rows in each of 50 calls');
        self::assertGreaterThanOrEqual(0.9 * ($updates - $updatesBefore), $hot - $hotBefore, 'at least 90% HOT updates');
    }

    public function testFourSessionsGetAtMostTheBurstPlusTheRateAndAtLeast95PercentOfTheRate(): void
    {
        // A global burst of 50 holds 0.5 s of refill. With a burst of 10
        // (0.1 s), a stall of all four sessions longer than 0.1 s on a loaded
        // runner (a parallel mutation run) filled the bucket and lost refill,
        // so the lower bound measured the scheduler and not the limiter.
        $limits = new RateLimits(new Rate(100, 50), [
            'a' => new Rate(20, 2),
        ]);
        $session = static function (PDO $pdo) use ($limits): array {
            $limiter = new PostgreSqlRateLimiter($pdo);
            $clock = $pdo->prepare('SELECT CAST(floor(EXTRACT(EPOCH FROM clock_timestamp()) * 1000) AS BIGINT)');
            $calls = [];
            $until = hrtime(true) + 3_000_000_000;
            while (hrtime(true) < $until) {
                $clock->execute();
                $before = (int) $clock->fetchColumn();
                $grants = $limiter->acquire([
                    'a' => 5,
                    'b' => 5,
                    'c' => 5,
                ], 15, $limits);
                $clock->execute();
                $after = (int) $clock->fetchColumn();
                if (array_sum($grants) > 0) {
                    $calls[] = [$before, $after, $grants];
                }
            }

            return $calls;
        };

        $calls = array_merge(...ConcurrentSessions::run(self::newConnection(...), [$session, $session, $session, $session]));

        // T is the database time between the first and the last grant. The
        // first grant is at or before the earliest "after", the last at or
        // after the latest "before": max(before) - min(after) <= T <= max(after) - min(before).
        $sum = array_sum(array_map(static fn (array $call): int => array_sum($call[2]), $calls));
        $longest = (max(array_column($calls, 1)) - min(array_column($calls, 0))) / 1_000;
        $shortest = (max(array_column($calls, 0)) - min(array_column($calls, 1))) / 1_000;
        self::assertGreaterThan(2.5, $shortest, 'the sessions ran for about 3 seconds');
        self::assertLessThanOrEqual(50 + 100 * $longest, $sum, 'the global burst plus the global rate');
        self::assertGreaterThanOrEqual(0.95 * 100 * $shortest, $sum, 'at least 95% of the global rate');

        $laneA = array_values(array_filter($calls, static fn (array $call): bool => $call[2]['a'] > 0));
        $laneASum = array_sum(array_map(static fn (array $call): int => $call[2]['a'], $laneA));
        $laneALongest = (max(array_column($laneA, 1)) - min(array_column($laneA, 0))) / 1_000;
        self::assertLessThanOrEqual(2 + 20 * $laneALongest, $laneASum, 'the lane burst plus the lane rate');
        self::assertGreaterThan(20, $laneASum, 'the lane got permits');
    }

    /** @return array<array-key, float> the tokens of each row, by name */
    private function tokens(): array
    {
        $statement = self::$pdo->query('SELECT name, tokens FROM webhook_rate_limit ORDER BY name COLLATE "C"');
        self::assertNotFalse($statement);
        $tokens = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tokens[(string) $row['name']] = (float) $row['tokens'];
        }

        return $tokens;
    }

    /** @return array{int, int} n_tup_upd and n_tup_hot_upd of webhook_rate_limit */
    private function updateCounts(): array
    {
        self::$pdo->query('SELECT pg_stat_clear_snapshot()');
        $statement = self::$pdo->query("SELECT n_tup_upd, n_tup_hot_upd FROM pg_stat_user_tables WHERE relname = 'webhook_rate_limit'");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);
        self::assertIsArray($row);

        return [(int) $row[0], (int) $row[1]];
    }

    private function createTrigger(string $timing, string $level, string $body): void
    {
        self::$pdo->exec("CREATE FUNCTION rate_limit_test_trigger() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN {$body}; END \$\$");
        self::$pdo->exec("CREATE TRIGGER rate_limit_test_trigger {$timing} ON webhook_rate_limit FOR EACH {$level} EXECUTE FUNCTION rate_limit_test_trigger()");
    }

    private static function fetchString(string $sql): string
    {
        $statement = self::$pdo->query($sql);
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }
}
