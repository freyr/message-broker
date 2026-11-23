<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Console\OutboxMaintainCommand;
use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use LogicException;
use PDOException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Partition retention: daily partitions are created ahead (CREATE + ATTACH);
 * an expired partition is detached concurrently and dropped. A partition with
 * unpublished rows is kept by default, and dropped only with the opt-in
 * dropUnpublished. There is no DEFAULT partition.
 */
final class PostgreSqlOutboxRetentionTest extends FunctionalTestCase
{
    private PostgreSqlOutboxRetention $partitions;
    private PostgreSqlOutboxStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->dailyPartitionNames() as $name) {
            self::$pdo->exec("DROP TABLE \"{$name}\"");
        }
        // Tests that keep a detached daily table leave it behind.
        $leftovers = self::$pdo->query("SELECT relname FROM pg_class WHERE relkind = 'r' AND NOT relispartition AND relname ~ '^outbox_messages_p[0-9]{8}$'");
        self::assertNotFalse($leftovers);
        foreach ($leftovers->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            self::$pdo->exec(sprintf('DROP TABLE "%s"', (string) $name));
        }
        $this->partitions = new PostgreSqlOutboxRetention(self::$pdo);
        $this->store = new PostgreSqlOutboxStore(self::$pdo);
    }

    public function testEnsureCreatesOnePartitionPerDayAheadAndIsIdempotent(): void
    {
        $today = self::day('2026-03-10');

        $names = $this->partitions->ensureCapacity(2, $today);
        $this->partitions->ensureCapacity(2, $today);

        self::assertSame(
            ['outbox_messages_p20260310', 'outbox_messages_p20260311', 'outbox_messages_p20260312'],
            $names,
        );
        self::assertSame($names, $this->dailyPartitionNames());
    }

    public function testPartitionDdlCreatesAndAttachesEachDayFromTodayThroughDaysAhead(): void
    {
        // A mid-day, non-UTC "today": the partitions still start at 00:00 UTC of that day.
        $today = new DateTimeImmutable('2026-03-10 13:45:30', new DateTimeZone('Europe/Warsaw'));

        $ddl = $this->partitions->partitionDdl(1, $today);

        self::assertSame([
            'CREATE TABLE IF NOT EXISTS "outbox_messages_p20260310" (LIKE outbox_messages INCLUDING DEFAULTS INCLUDING CONSTRAINTS)',
            "ALTER TABLE outbox_messages ATTACH PARTITION \"outbox_messages_p20260310\" FOR VALUES FROM ('2026-03-10 00:00:00') TO ('2026-03-11 00:00:00')",
            'CREATE TABLE IF NOT EXISTS "outbox_messages_p20260311" (LIKE outbox_messages INCLUDING DEFAULTS INCLUDING CONSTRAINTS)',
            "ALTER TABLE outbox_messages ATTACH PARTITION \"outbox_messages_p20260311\" FOR VALUES FROM ('2026-03-11 00:00:00') TO ('2026-03-12 00:00:00')",
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

    public function testEnsurePartitionsRejectsNegativeDaysAheadAndCreatesNothing(): void
    {
        try {
            $this->partitions->ensureCapacity(-1, self::day('2026-03-10'));
            self::fail('a negative daysAhead must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('daysAhead must be 0 or more, got -1', $error->getMessage());
        }

        self::assertSame([], $this->dailyPartitionNames());
    }

    public function testEnsurePartitionsCreatesThreeDaysAheadByDefault(): void
    {
        $names = $this->partitions->ensureCapacity(3, today: self::day('2026-03-10'));

        self::assertSame(
            ['outbox_messages_p20260310', 'outbox_messages_p20260311', 'outbox_messages_p20260312', 'outbox_messages_p20260313'],
            $names,
        );
        self::assertSame($names, $this->dailyPartitionNames());
    }

    public function testEnsureAttachesAStandaloneDailyTableThatIsNotAttachedYet(): void
    {
        self::$pdo->exec('CREATE TABLE outbox_messages_p20260310 (LIKE outbox_messages INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');

        $names = $this->partitions->ensureCapacity(0, self::day('2026-03-10'));

        self::assertSame(['outbox_messages_p20260310'], $names);
        self::assertSame(['outbox_messages_p20260310'], $this->dailyPartitionNames(), 'the leftover table is now a partition');
        self::assertSame(0, $this->standaloneDailyTableCount());
    }

    public function testPurgeReportsTheCutoffAsMidnightUtcOfTodayMinusRetention(): void
    {
        $report = $this->partitions->purge(2, today: new DateTimeImmutable('2026-03-05 13:45:30', new DateTimeZone('UTC')));

        self::assertSame('2026-03-03 00:00:00', $report->cutoff->format('Y-m-d H:i:s'));
        self::assertSame([], $report->dropped);
    }

    public function testRowsLandInTheirDailyPartition(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-10'));

        $this->insertAt('2026-03-10 13:00:00');

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages_p20260310'));
    }

    public function testInsertForADayWithNoPartitionFails(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('no partition of relation');

        $this->insertAt('2026-03-10 13:00:00');
    }

    public function testPurgeKeepsAnExpiredPartitionWithUnpublishedRowsAndDropsTheRest(): void
    {
        $this->partitions->ensureCapacity(3, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: true);  // expired, all published → dropped
        $this->insertAt('2026-03-02 10:00:00', published: false); // expired, unpublished → kept, reported
        $this->insertAt('2026-03-02 11:00:00', published: false);
        $this->insertAt('2026-03-02 12:00:00', published: true);
        $this->insertAt('2026-03-04 10:00:00', published: false); // inside retention → untouched

        // today 2026-03-05, retention 2 days → cutoff 2026-03-03 00:00
        $report = $this->partitions->purge(2, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301'], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260302' => 2,
        ], $report->kept);
        self::assertSame([], $report->unpublished);
        self::assertTrue($report->hasKept());
        self::assertSame(2, $report->unpublishedRowsKept());
        self::assertSame(0, $report->unpublishedRowsDropped());
        self::assertSame(
            ['outbox_messages_p20260302', 'outbox_messages_p20260303', 'outbox_messages_p20260304'],
            $this->dailyPartitionNames(),
        );
        self::assertSame(4, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testPurgeDropsAKeptPartitionOnALaterRunOnceItsRowsArePublished(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: false);

        $first = $this->partitions->purge(1, today: self::day('2026-03-05'));
        self::assertSame([], $first->dropped);
        self::assertSame([
            'outbox_messages_p20260301' => 1,
        ], $first->kept);

        self::$pdo->exec('UPDATE outbox_messages SET published_at = created_at');
        $second = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301'], $second->dropped);
        self::assertSame([], $second->kept);
        self::assertFalse($second->hasKept());
        self::assertSame([], $this->dailyPartitionNames());
    }

    public function testPurgeWithDropUnpublishedDropsThePartitionAndReportsTheRows(): void
    {
        $this->partitions->ensureCapacity(3, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: true);
        $this->insertAt('2026-03-02 10:00:00', published: false);
        $this->insertAt('2026-03-02 11:00:00', published: false);
        $this->insertAt('2026-03-04 10:00:00', published: false); // inside retention → untouched

        $report = $this->partitions->purge(2, today: self::day('2026-03-05'), dropUnpublished: true);

        self::assertSame(['outbox_messages_p20260301', 'outbox_messages_p20260302'], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260302' => 2,
        ], $report->unpublished);
        self::assertSame([], $report->kept);
        self::assertSame(2, $report->unpublishedRowsDropped());
        self::assertSame(['outbox_messages_p20260303', 'outbox_messages_p20260304'], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount(), 'detached tables are dropped too');
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testDryRunReportsWhatWouldBeKeptAndDroppedWithoutChangingAnything(): void
    {
        $this->partitions->ensureCapacity(1, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: false);
        $this->insertAt('2026-03-02 10:00:00', published: true);

        $report = $this->partitions->purge(1, dryRun: true, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260302'], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260301' => 1,
        ], $report->kept);
        self::assertSame(['outbox_messages_p20260301', 'outbox_messages_p20260302'], $this->dailyPartitionNames());
    }

    public function testDryRunWithDropUnpublishedReportsWithoutDropping(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: false);

        $report = $this->partitions->purge(1, dryRun: true, today: self::day('2026-03-05'), dropUnpublished: true);

        self::assertSame(['outbox_messages_p20260301'], $report->dropped);
        self::assertSame(1, $report->unpublishedRowsDropped());
        self::assertSame([], $report->kept);
        self::assertSame(['outbox_messages_p20260301'], $this->dailyPartitionNames());
    }

    public function testPurgeDropsAnExpiredPartitionWhoseOnlyUnpublishedRowsAreAbandonedAndReportsThem(): void
    {
        $this->partitions->ensureCapacity(3, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: true);  // expired, all published → dropped, nothing abandoned
        $this->insertAt('2026-03-02 10:00:00', abandoned: true);  // expired, abandoned only → dropped, reported
        $this->insertAt('2026-03-02 11:00:00', abandoned: true);
        $this->insertAt('2026-03-02 12:00:00', published: true);
        $this->insertAt('2026-03-04 10:00:00', abandoned: true);  // inside retention → untouched

        $report = $this->partitions->purge(2, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301', 'outbox_messages_p20260302'], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260302' => 2,
        ], $report->abandoned);
        self::assertSame(2, $report->abandonedRowsDropped());
        self::assertSame([], $report->kept, 'an abandoned row does not keep its partition');
        self::assertSame([], $report->unpublished);
        self::assertSame(['outbox_messages_p20260303', 'outbox_messages_p20260304'], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testPurgeKeepsAPartitionWithAbandonedAndOtherUnpublishedRowsAndCountsOnlyTheOthers(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', abandoned: true);
        $this->insertAt('2026-03-01 11:00:00', abandoned: true);
        $this->insertAt('2026-03-01 12:00:00');

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame([], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260301' => 1,
        ], $report->kept);
        self::assertSame([], $report->abandoned, 'a kept partition drops no abandoned row');
        self::assertSame(['outbox_messages_p20260301'], $this->dailyPartitionNames());
        self::assertSame(3, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testDryRunReportsTheAbandonedRowsThatWouldBeDropped(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', abandoned: true);
        $this->insertAt('2026-03-01 11:00:00', published: true);

        $report = $this->partitions->purge(1, dryRun: true, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301'], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260301' => 1,
        ], $report->abandoned);
        self::assertSame([], $report->kept);
        self::assertSame(['outbox_messages_p20260301'], $this->dailyPartitionNames(), 'a dry run drops nothing');
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testPurgeWithDropUnpublishedReportsTheUnpublishedAndTheAbandonedRowsApart(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', abandoned: true);
        $this->insertAt('2026-03-01 11:00:00');
        $this->insertAt('2026-03-01 12:00:00');
        $this->insertAt('2026-03-01 13:00:00', published: true);

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'), dropUnpublished: true);

        self::assertSame(['outbox_messages_p20260301'], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260301' => 2,
        ], $report->unpublished, 'the rows that are not abandoned');
        self::assertSame([
            'outbox_messages_p20260301' => 1,
        ], $report->abandoned);
        self::assertSame([], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount());
    }

    public function testPurgeKeepsTheDetachedTableWhenAnUnpublishedRowArrivesBetweenTheCountAndTheDetach(): void
    {
        $this->partitions->ensureCapacity(1, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: true);
        $this->insertAt('2026-03-02 10:00:00', published: true);

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
                if (str_contains($statement, 'DETACH PARTITION "outbox_messages_p20260301" CONCURRENTLY')) {
                    $this->rival->exec(
                        "INSERT INTO outbox_messages (id, lane, message_name, body, headers, created_at, available_at, attempts)
                         VALUES ('01900000-0000-7000-8000-0000000000b1', 'retention', 'm', '\\x7b7d', '{}', '2026-03-01 11:00:00', '2026-03-01 11:00:00', 0)",
                    );
                }

                return parent::exec($statement);
            }
        };
        $manager = new PostgreSqlOutboxRetention($racing);

        $report = $manager->purge(1, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260302'], $report->dropped, 'the loop goes on after a kept table');
        self::assertSame([
            'outbox_messages_p20260301' => 1,
        ], $report->kept);
        self::assertSame([], $report->abandoned);
        self::assertSame(0, $this->standaloneDailyTableCount(), 'the table is attached again, not left detached');
        self::assertSame(['outbox_messages_p20260301'], $this->dailyPartitionNames());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'));

        $this->publishAllClaimable('the relay claims the late row through outbox_messages');

        // detach (second time, with the old CHECK constraint in place), then drop
        $later = $this->partitions->purge(1, today: self::day('2026-03-05'));
        self::assertSame(['outbox_messages_p20260301'], $later->dropped);
        self::assertSame([], $later->kept);
        self::assertSame([], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount());
    }

    public function testPurgeAttachesAnExpiredDetachedTableThatHoldsUnpublishedRows(): void
    {
        self::$pdo->exec('CREATE TABLE outbox_messages_p20260301 (LIKE outbox_messages)');
        self::$pdo->exec("INSERT INTO outbox_messages_p20260301 (id, lane, message_name, body, headers, created_at, available_at, attempts)
            VALUES ('01900000-0000-7000-8000-0000000000a1', 'retention', 'm', '\\x7b7d', '{}', '2026-03-01 10:00:00', '2026-03-01 10:00:00', 0)");

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame([], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260301' => 1,
        ], $report->kept);
        self::assertSame(0, $this->standaloneDailyTableCount());
        self::assertSame(['outbox_messages_p20260301'], $this->dailyPartitionNames());

        $this->publishAllClaimable('the relay can claim the row of the re-attached table');
        $later = $this->partitions->purge(1, today: self::day('2026-03-05'));
        self::assertSame(['outbox_messages_p20260301'], $later->dropped);
        self::assertSame([], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount());
    }

    public function testDryRunLeavesAnExpiredDetachedTableDetached(): void
    {
        self::$pdo->exec('CREATE TABLE outbox_messages_p20260301 (LIKE outbox_messages)');
        self::$pdo->exec("INSERT INTO outbox_messages_p20260301 (id, lane, message_name, body, headers, created_at, available_at, attempts)
            VALUES ('01900000-0000-7000-8000-0000000000a1', 'retention', 'm', '\\x7b7d', '{}', '2026-03-01 10:00:00', '2026-03-01 10:00:00', 0)");

        $report = $this->partitions->purge(1, dryRun: true, today: self::day('2026-03-05'));

        self::assertSame([
            'outbox_messages_p20260301' => 1,
        ], $report->kept);
        self::assertSame(1, $this->standaloneDailyTableCount());
    }

    public function testPurgeFinalizesAndAttachesAPartitionWithAPendingDetachThatHoldsUnpublishedRows(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: false);
        $this->leaveDetachPending('outbox_messages_p20260301');

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame([], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260301' => 1,
        ], $report->kept);
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM pg_inherits WHERE inhdetachpending'));
        self::assertSame(['outbox_messages_p20260301'], $this->dailyPartitionNames());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'));

        $this->publishAllClaimable('the relay claims the row of the re-attached partition');
        $later = $this->partitions->purge(1, today: self::day('2026-03-05'));
        self::assertSame(['outbox_messages_p20260301'], $later->dropped);
        self::assertSame([], $this->dailyPartitionNames());
    }

    public function testPurgeFinishesAnInterruptedConcurrentDetach(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-01'));

        $this->leaveDetachPending('outbox_messages_p20260301');

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301'], $report->dropped);
        self::assertSame([], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount());
    }

    public function testPurgeDropsAnExpiredDetachedTableLeftByAnEarlierRun(): void
    {
        self::$pdo->exec('CREATE TABLE outbox_messages_p20260301 (LIKE outbox_messages)'); // expired leftover
        self::$pdo->exec('CREATE TABLE outbox_messages_p20260310 (LIKE outbox_messages)'); // not expired: kept

        $report = $this->partitions->purge(1, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301'], $report->dropped);
        self::assertSame(1, $this->standaloneDailyTableCount());
        self::$pdo->exec('DROP TABLE outbox_messages_p20260310');
    }

    public function testPurgeRefusesToRunInsideATransaction(): void
    {
        self::$pdo->beginTransaction();
        try {
            $this->expectException(LogicException::class);
            $this->partitions->purge(1);
        } finally {
            self::$pdo->rollBack();
        }
    }

    public function testMaintainCommandKeepsAnExpiredPartitionWithUnpublishedRowsAndFails(): void
    {
        $command = new CommandTester(new OutboxMaintainCommand($this->partitions));

        $command->execute([
            '--retention-days' => '1',
            '--premake-days' => '1',
        ]);
        $command->assertCommandIsSuccessful();
        self::assertContains('outbox_messages_p'.gmdate('Ymd'), $this->dailyPartitionNames());

        $old = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 day')->setTime(0, 0);
        $name = 'outbox_messages_p'.$old->format('Ymd');
        $this->partitions->ensureCapacity(0, $old);
        $this->insertAt($old->format('Y-m-d').' 10:00:00', published: false);

        $status = $command->execute([
            '--retention-days' => '1',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString("{$name} is kept: it holds 1 unpublished row(s)", $command->getDisplay());
        self::assertContains($name, $this->dailyPartitionNames());

        self::$pdo->exec('UPDATE outbox_messages SET published_at = created_at');
        $status = $command->execute([
            '--retention-days' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("Dropped {$name}.", $command->getDisplay());
        self::assertNotContains($name, $this->dailyPartitionNames());
    }

    public function testMaintainCommandDropsUnpublishedRowsWithTheOptIn(): void
    {
        $command = new CommandTester(new OutboxMaintainCommand($this->partitions));
        $old = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 day')->setTime(0, 0);
        $name = 'outbox_messages_p'.$old->format('Ymd');
        $this->partitions->ensureCapacity(0, $old);
        $this->insertAt($old->format('Y-m-d').' 10:00:00', published: false);

        $status = $command->execute([
            '--retention-days' => '1',
            '--drop-unpublished' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("Dropped {$name}.", $command->getDisplay());
        self::assertStringContainsString("{$name} held 1 unpublished row(s)", $command->getDisplay());
        self::assertNotContains($name, $this->dailyPartitionNames());
    }

    public function testMaintainCommandRejectsInvalidOptions(): void
    {
        $command = new CommandTester(new OutboxMaintainCommand($this->partitions));

        self::assertSame(Command::INVALID, $command->execute([
            '--retention-days' => '0',
        ]));
        self::assertSame(Command::INVALID, $command->execute([
            '--premake-days' => '-1',
        ]));
    }

    /**
     * An open transaction that uses outbox_messages makes the second phase of
     * DETACH ... CONCURRENTLY wait; the timeout cancels it there, and the
     * partition stays "detach pending".
     */
    private function leaveDetachPending(string $partition): void
    {
        $rival = self::newConnection();
        $rival->beginTransaction();
        $rival->query('SELECT COUNT(*) FROM outbox_messages');
        self::$pdo->exec("SET statement_timeout = '1s'");
        try {
            self::$pdo->exec("ALTER TABLE outbox_messages DETACH PARTITION {$partition} CONCURRENTLY");
            self::fail('the detach must wait for the open transaction');
        } catch (PDOException) {
        } finally {
            self::$pdo->exec('RESET statement_timeout');
        }
        $rival->rollBack();
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM pg_inherits WHERE inhdetachpending'));
    }

    private function publishAllClaimable(string $message): void
    {
        $published = $this->store->drainClaimed(
            'retention',
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(array_map(static fn (OutboxRecord $record): string => $record->id, $claimed)),
        );
        self::assertSame(1, $published, $message);
    }

    private function insertAt(string $createdAtUtc, bool $published = false, bool $abandoned = false): void
    {
        $createdAt = EpochMillis::fromDateTime(new DateTimeImmutable($createdAtUtc, new DateTimeZone('UTC')));
        $id = Uuid::v7()->toString();
        $this->store->insert(new OutboxRecord(
            id: $id,
            lane: 'retention',
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: $createdAt,
        ));
        if ($published) {
            self::$pdo->exec("UPDATE outbox_messages SET published_at = created_at WHERE id = '{$id}'");
        }
        if ($abandoned) {
            self::assertSame(1, $this->store->abandon($id));
        }
    }

    /** @return list<string> the daily partitions attached to outbox_messages */
    private function dailyPartitionNames(): array
    {
        $statement = self::$pdo->query(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'outbox_messages'::regclass AND c.relname LIKE 'outbox\\_messages\\_p%'
             ORDER BY c.relname",
        );
        self::assertNotFalse($statement);

        /** @var list<string> $names */
        $names = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return $names;
    }

    private function standaloneDailyTableCount(): int
    {
        return self::fetchInt(
            "SELECT COUNT(*) FROM pg_class WHERE relkind = 'r' AND NOT relispartition
             AND relname ~ '^outbox_messages_p[0-9]{8}$'",
        );
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
