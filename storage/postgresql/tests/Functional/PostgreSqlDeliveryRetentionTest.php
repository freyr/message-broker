<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Webhook\DeliveryRetention;
use LogicException;
use PDOException;
use Symfony\Component\Uid\Uuid;

/**
 * Partition retention of webhook_deliveries: daily partitions are created
 * ahead (CREATE + ATTACH); an expired partition is detached concurrently and
 * dropped. A partition with an unfinished row (completed_at IS NULL) is kept,
 * with no option to drop it. There is no DEFAULT partition.
 */
final class PostgreSqlDeliveryRetentionTest extends FunctionalTestCase
{
    private PostgreSqlDeliveryRetention $partitions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->dailyPartitionNames() as $name) {
            self::$pdo->exec("DROP TABLE \"{$name}\"");
        }
        // Tests that keep a detached daily table leave it behind.
        $leftovers = self::$pdo->query("SELECT relname FROM pg_class WHERE relkind = 'r' AND NOT relispartition AND relname ~ '^webhook_deliveries_p[0-9]{8}$'");
        self::assertNotFalse($leftovers);
        foreach ($leftovers->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            self::$pdo->exec(sprintf('DROP TABLE "%s"', (string) $name));
        }
        $this->partitions = new PostgreSqlDeliveryRetention(self::$pdo);
    }

    public function testEnsureCreatesOnePartitionPerDayAheadAndIsIdempotent(): void
    {
        $today = self::day('2026-03-10');

        $names = $this->partitions->ensureCapacity(2, $today);
        $this->partitions->ensureCapacity(2, $today);

        self::assertSame(
            ['webhook_deliveries_p20260310', 'webhook_deliveries_p20260311', 'webhook_deliveries_p20260312'],
            $names,
        );
        self::assertSame($names, $this->dailyPartitionNames());
    }

    public function testEnsureWithoutTodayStartsAtTheCurrentUtcDay(): void
    {
        $before = 'webhook_deliveries_p'.gmdate('Ymd');
        $names = $this->partitions->ensureCapacity(0);
        $after = 'webhook_deliveries_p'.gmdate('Ymd');

        self::assertCount(1, $names);
        self::assertContains($names[0], [$before, $after], 'a run at midnight can see either day');
    }

    public function testPartitionDdlCreatesAndAttachesEachDayFromTodayThroughDaysAhead(): void
    {
        // A mid-day, non-UTC "today": the partitions still start at 00:00 UTC of that day.
        $today = new DateTimeImmutable('2026-03-10 13:45:30', new DateTimeZone('Europe/Warsaw'));

        $ddl = $this->partitions->partitionDdl(1, $today);

        self::assertSame([
            'CREATE TABLE IF NOT EXISTS "webhook_deliveries_p20260310" (LIKE webhook_deliveries INCLUDING DEFAULTS INCLUDING CONSTRAINTS)',
            "ALTER TABLE webhook_deliveries ATTACH PARTITION \"webhook_deliveries_p20260310\" FOR VALUES FROM ('2026-03-10 00:00:00') TO ('2026-03-11 00:00:00')",
            'CREATE TABLE IF NOT EXISTS "webhook_deliveries_p20260311" (LIKE webhook_deliveries INCLUDING DEFAULTS INCLUDING CONSTRAINTS)',
            "ALTER TABLE webhook_deliveries ATTACH PARTITION \"webhook_deliveries_p20260311\" FOR VALUES FROM ('2026-03-11 00:00:00') TO ('2026-03-12 00:00:00')",
        ], $ddl);
    }

    public function testPartitionDdlForZeroDaysAheadCoversOnlyToday(): void
    {
        self::assertCount(2, $this->partitions->partitionDdl(0, self::day('2026-03-10')));
    }

    public function testNegativeDaysAheadIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('daysAhead must be 0 or more, got -1');

        $this->partitions->partitionDdl(-1, self::day('2026-03-10'));
    }

    public function testEnsureRejectsNegativeDaysAheadAndCreatesNothing(): void
    {
        try {
            $this->partitions->ensureCapacity(-1, self::day('2026-03-10'));
            self::fail('a negative daysAhead must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('daysAhead must be 0 or more, got -1', $error->getMessage());
        }

        self::assertSame([], $this->dailyPartitionNames());
    }

    public function testEnsureAttachesAStandaloneDailyTableThatIsNotAttachedYet(): void
    {
        self::$pdo->exec('CREATE TABLE webhook_deliveries_p20260310 (LIKE webhook_deliveries INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');

        $names = $this->partitions->ensureCapacity(0, self::day('2026-03-10'));

        self::assertSame(['webhook_deliveries_p20260310'], $names);
        self::assertSame(['webhook_deliveries_p20260310'], $this->dailyPartitionNames(), 'the leftover table is now a partition');
        self::assertSame(0, $this->standaloneDailyTableCount());
    }

    public function testEnsureIgnoresATableOfAnotherNamePattern(): void
    {
        self::$pdo->exec('CREATE TABLE webhook_deliveries_p2026031 (LIKE webhook_deliveries)');
        try {
            $names = $this->partitions->ensureCapacity(0, self::day('2026-03-10'));

            self::assertSame(['webhook_deliveries_p20260310'], $names);
            self::assertSame(['webhook_deliveries_p20260310'], $this->dailyPartitionNames());
        } finally {
            self::$pdo->exec('DROP TABLE webhook_deliveries_p2026031');
        }
    }

    public function testRowsLandInTheirDailyPartition(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-10'));

        $this->insertAt('2026-03-10 13:00:00');

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries_p20260310'));
    }

    public function testInsertForADayWithNoPartitionFails(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('no partition of relation');

        $this->insertAt('2026-03-10 13:00:00');
    }

    public function testItIsTheDeliveryRetentionOfTheWebhookQueue(): void
    {
        self::assertInstanceOf(DeliveryRetention::class, $this->partitions);
    }

    public function testPurgeReportsTheCutoffAsMidnightUtcOfTodayMinusRetention(): void
    {
        $report = $this->partitions->purge(2, today: new DateTimeImmutable('2026-03-05 13:45:30', new DateTimeZone('Europe/Warsaw')));

        self::assertSame('2026-03-03 00:00:00', $report->cutoff->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $report->cutoff->getTimezone()->getName());
        self::assertSame([], $report->dropped);
    }

    public function testPurgeWithoutTodayUsesTheCurrentUtcDay(): void
    {
        $before = gmdate('Y-m-d', time() - 86_400).' 00:00:00';
        $report = $this->partitions->purge(1);
        $after = gmdate('Y-m-d', time() - 86_400).' 00:00:00';

        self::assertContains($report->cutoff->format('Y-m-d H:i:s'), [$before, $after], 'the day before the current UTC day (a run at midnight can see either day)');
    }

    public function testPurgeRejectsARetentionOfLessThanOneDay(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));

        try {
            $this->partitions->purge(0, today: self::day('2026-03-05'));
            self::fail('a retention of 0 days must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('retentionDays must be 1 or more, got 0', $error->getMessage());
        }
        self::assertSame(['webhook_deliveries_p20260301'], $this->dailyPartitionNames());
    }

    public function testPurgeKeepsAnExpiredPartitionWithUnfinishedRowsAndDropsTheRest(): void
    {
        $this->partitions->ensureCapacity(3, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', finished: true);  // expired, all finished → dropped
        $this->insertAt('2026-03-02 10:00:00', finished: false); // expired, unfinished → kept, reported
        $this->insertAt('2026-03-02 11:00:00', finished: false);
        $this->insertAt('2026-03-02 12:00:00', finished: true);
        $this->insertAt('2026-03-04 10:00:00', finished: true);  // inside retention → untouched

        // today 2026-03-05, retention 2 days → cutoff 2026-03-03 00:00
        $report = $this->partitions->purge(2, today: self::day('2026-03-05'));

        self::assertSame(['webhook_deliveries_p20260301'], $report->dropped);
        self::assertSame([
            'webhook_deliveries_p20260302' => 2,
        ], $report->kept);
        self::assertSame([], $report->unpublished);
        self::assertSame([], $report->abandoned);
        self::assertSame(
            ['webhook_deliveries_p20260302', 'webhook_deliveries_p20260303', 'webhook_deliveries_p20260304'],
            $this->dailyPartitionNames(),
        );
        self::assertSame(0, $this->standaloneDailyTableCount(), 'the dropped partition is not left detached');
        self::assertSame(4, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'));
    }

    public function testPurgeDropsAPartitionWhoseEndIsTheCutoffAndKeepsTheNextOne(): void
    {
        $this->partitions->ensureCapacity(1, self::day('2026-03-03'));

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame(['webhook_deliveries_p20260303'], $report->dropped, 'it ends at the cutoff 2026-03-04 00:00');
        self::assertSame(['webhook_deliveries_p20260304'], $this->dailyPartitionNames());
    }

    public function testPurgeDropsAKeptPartitionOnALaterRunOnceItsRowsAreFinished(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', finished: false);

        $first = $this->partitions->purge(1, today: self::day('2026-03-05'));
        self::assertSame([], $first->dropped);
        self::assertSame([
            'webhook_deliveries_p20260301' => 1,
        ], $first->kept);

        self::$pdo->exec('UPDATE webhook_deliveries SET completed_at = created_at, outcome = 2');
        $second = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame(['webhook_deliveries_p20260301'], $second->dropped);
        self::assertSame([], $second->kept);
        self::assertSame([], $this->dailyPartitionNames());
    }

    public function testDryRunReportsWhatWouldBeKeptAndDroppedWithoutChangingAnything(): void
    {
        $this->partitions->ensureCapacity(1, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', finished: false);
        $this->insertAt('2026-03-02 10:00:00', finished: true);

        $report = $this->partitions->purge(1, dryRun: true, today: self::day('2026-03-05'));

        self::assertSame(['webhook_deliveries_p20260302'], $report->dropped);
        self::assertSame([
            'webhook_deliveries_p20260301' => 1,
        ], $report->kept);
        self::assertSame(['webhook_deliveries_p20260301', 'webhook_deliveries_p20260302'], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount());
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'));
    }

    public function testPurgeKeepsTheDetachedTableWhenAnUnfinishedRowArrivesBetweenTheCountAndTheDetach(): void
    {
        $this->partitions->ensureCapacity(1, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', finished: true);
        $this->insertAt('2026-03-02 10:00:00', finished: true);

        // A row commits on another connection after the first count and just
        // before the detach runs.
        $rival = self::newConnection();
        [$dsn, $user, $password] = self::credentials();
        $racing = new class($dsn, $user, $password, $rival) extends \PDO {
            public function __construct(
                string $dsn,
                string $user,
                string $password,
                private readonly \PDO $rival
            ) {
                parent::__construct($dsn, $user, $password, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
            }

            public function exec(string $statement): int|false
            {
                if (str_contains($statement, 'DETACH PARTITION "webhook_deliveries_p20260301" CONCURRENTLY')) {
                    $this->rival->exec(
                        "INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at)
                         VALUES ('01900000-0000-7000-8000-0000000000b1', '01900000-0000-7000-8000-0000000000b1', 'retention', 'm', '\\x7b7d', '{}', '2026-03-01 11:00:00', '2026-03-01 11:00:00')",
                    );
                }

                return parent::exec($statement);
            }
        };
        $manager = new PostgreSqlDeliveryRetention($racing);

        $report = $manager->purge(1, today: self::day('2026-03-05'));

        self::assertSame(['webhook_deliveries_p20260302'], $report->dropped, 'the loop goes on after a kept table');
        self::assertSame([
            'webhook_deliveries_p20260301' => 1,
        ], $report->kept);
        self::assertSame(0, $this->standaloneDailyTableCount(), 'the table is attached again, not left detached');
        self::assertSame(['webhook_deliveries_p20260301'], $this->dailyPartitionNames());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries WHERE completed_at IS NULL'));

        // detach (second time, with the old CHECK constraint in place), then drop
        self::$pdo->exec('UPDATE webhook_deliveries SET completed_at = created_at, outcome = 1');
        $later = $this->partitions->purge(1, today: self::day('2026-03-05'));
        self::assertSame(['webhook_deliveries_p20260301'], $later->dropped);
        self::assertSame([], $later->kept);
        self::assertSame([], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount());
    }

    public function testPurgeAttachesAnExpiredDetachedTableThatHoldsUnfinishedRows(): void
    {
        $this->createDetachedTableWithAnUnfinishedRow('webhook_deliveries_p20260301', '2026-03-01 10:00:00');

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame([], $report->dropped);
        self::assertSame([
            'webhook_deliveries_p20260301' => 1,
        ], $report->kept);
        self::assertSame(0, $this->standaloneDailyTableCount());
        self::assertSame(['webhook_deliveries_p20260301'], $this->dailyPartitionNames());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries WHERE completed_at IS NULL'), 'the claim sees the row again');

        self::$pdo->exec('UPDATE webhook_deliveries SET completed_at = created_at, outcome = 1');
        $later = $this->partitions->purge(1, today: self::day('2026-03-05'));
        self::assertSame(['webhook_deliveries_p20260301'], $later->dropped);
        self::assertSame([], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount());
    }

    public function testDryRunLeavesAnExpiredDetachedTableDetached(): void
    {
        $this->createDetachedTableWithAnUnfinishedRow('webhook_deliveries_p20260301', '2026-03-01 10:00:00');

        $report = $this->partitions->purge(1, dryRun: true, today: self::day('2026-03-05'));

        self::assertSame([
            'webhook_deliveries_p20260301' => 1,
        ], $report->kept);
        self::assertSame(1, $this->standaloneDailyTableCount());
    }

    public function testPurgeFinalizesAndAttachesAPartitionWithAPendingDetachThatHoldsUnfinishedRows(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', finished: false);
        $this->leaveDetachPending('webhook_deliveries_p20260301');

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame([], $report->dropped);
        self::assertSame([
            'webhook_deliveries_p20260301' => 1,
        ], $report->kept);
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM pg_inherits WHERE inhdetachpending'));
        self::assertSame(['webhook_deliveries_p20260301'], $this->dailyPartitionNames());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries WHERE completed_at IS NULL'));
    }

    public function testDryRunLeavesAPendingDetachPending(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', finished: false);
        $this->leaveDetachPending('webhook_deliveries_p20260301');

        $report = $this->partitions->purge(1, dryRun: true, today: self::day('2026-03-05'));

        self::assertSame([
            'webhook_deliveries_p20260301' => 1,
        ], $report->kept);
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM pg_inherits WHERE inhdetachpending'));
        self::$pdo->exec('ALTER TABLE webhook_deliveries DETACH PARTITION webhook_deliveries_p20260301 FINALIZE');
    }

    public function testPurgeFinishesAnInterruptedConcurrentDetach(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', finished: true);
        $this->leaveDetachPending('webhook_deliveries_p20260301');

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame(['webhook_deliveries_p20260301'], $report->dropped);
        self::assertSame([], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount());
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM pg_inherits WHERE inhdetachpending'));
    }

    public function testPurgeDropsAnExpiredDetachedTableLeftByAnEarlierRun(): void
    {
        self::$pdo->exec('CREATE TABLE webhook_deliveries_p20260301 (LIKE webhook_deliveries)'); // expired leftover
        self::$pdo->exec('CREATE TABLE webhook_deliveries_p20260310 (LIKE webhook_deliveries)'); // not expired: kept

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame(['webhook_deliveries_p20260301'], $report->dropped);
        self::assertSame(1, $this->standaloneDailyTableCount());
        self::$pdo->exec('DROP TABLE webhook_deliveries_p20260310');
    }

    public function testPurgeLeavesTheTablesOfOtherNamesAndOfTheOutboxAlone(): void
    {
        self::$pdo->exec('CREATE TABLE webhook_deliveries_p2026030 (LIKE webhook_deliveries)');
        (new PostgreSqlOutboxRetention(self::$pdo))->ensureCapacity(0, self::day('2026-03-01'));
        try {
            $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

            self::assertSame([], $report->dropped);
            self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relname = 'webhook_deliveries_p2026030'"));
            self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relname = 'outbox_messages_p20260301'"));
        } finally {
            self::$pdo->exec('DROP TABLE webhook_deliveries_p2026030');
            self::$pdo->exec('DROP TABLE outbox_messages_p20260301');
        }
    }

    public function testPurgeRefusesToRunInsideATransaction(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        self::$pdo->beginTransaction();
        try {
            $this->partitions->purge(1, today: self::day('2026-03-05'));
            self::fail('purge must refuse an open transaction');
        } catch (LogicException $error) {
            self::assertSame('Webhook delivery purge cannot run inside a transaction (DETACH PARTITION CONCURRENTLY).', $error->getMessage());
        } finally {
            self::$pdo->rollBack();
        }
        self::assertSame(['webhook_deliveries_p20260301'], $this->dailyPartitionNames());
    }

    private function insertAt(string $createdAtUtc, bool $finished = false): void
    {
        $id = Uuid::v7()->toString();
        $completedAt = $finished ? "'{$createdAtUtc}'" : 'NULL';
        $outcome = $finished ? '1' : 'NULL';
        self::$pdo->exec(
            "INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at, completed_at, outcome)
             VALUES ('{$id}', '{$id}', 'retention', 'order.placed', '\\x7b7d', '{}', '{$createdAtUtc}', '{$createdAtUtc}', {$completedAt}, {$outcome})",
        );
    }

    private function createDetachedTableWithAnUnfinishedRow(string $name, string $createdAtUtc): void
    {
        self::$pdo->exec("CREATE TABLE {$name} (LIKE webhook_deliveries)");
        self::$pdo->exec("INSERT INTO {$name} (id, message_id, lane, message_name, body, headers, created_at, available_at, attempts)
            VALUES ('01900000-0000-7000-8000-0000000000a1', '01900000-0000-7000-8000-0000000000a1', 'retention', 'm', '\\x7b7d', '{}', '{$createdAtUtc}', '{$createdAtUtc}', 0)");
    }

    /**
     * An open transaction that uses webhook_deliveries makes the second phase
     * of DETACH ... CONCURRENTLY wait; the timeout cancels it there, and the
     * partition stays "detach pending".
     */
    private function leaveDetachPending(string $partition): void
    {
        $rival = self::newConnection();
        $rival->beginTransaction();
        $rival->query('SELECT COUNT(*) FROM webhook_deliveries');
        self::$pdo->exec("SET statement_timeout = '1s'");
        try {
            self::$pdo->exec("ALTER TABLE webhook_deliveries DETACH PARTITION {$partition} CONCURRENTLY");
            self::fail('the detach must wait for the open transaction');
        } catch (PDOException) {
        } finally {
            self::$pdo->exec('RESET statement_timeout');
        }
        $rival->rollBack();
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM pg_inherits WHERE inhdetachpending'));
    }

    /** @return list<string> the daily partitions attached to webhook_deliveries */
    private function dailyPartitionNames(): array
    {
        $statement = self::$pdo->query(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'webhook_deliveries'::regclass AND c.relname LIKE 'webhook\\_deliveries\\_p%'
             ORDER BY c.relname",
        );
        self::assertNotFalse($statement);

        /** @var list<string> */
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function standaloneDailyTableCount(): int
    {
        return self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relkind = 'r' AND NOT relispartition AND relname ~ '^webhook_deliveries_p[0-9]{8}$'");
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
