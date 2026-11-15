<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Console\OutboxMaintainCommand;
use Freyr\MessageBroker\Outbox\OutboxPartitionManager;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use LogicException;
use PDOException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Partition retention: daily partitions are created ahead (CREATE + ATTACH);
 * an expired partition is detached concurrently and dropped, unpublished rows
 * included (the retention period is their age limit). There is no DEFAULT
 * partition.
 */
final class OutboxPartitionManagerTest extends FunctionalTestCase
{
    private OutboxPartitionManager $partitions;
    private PdoOutboxStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->dailyPartitionNames() as $name) {
            self::$pdo->exec("DROP TABLE \"{$name}\"");
        }
        $this->partitions = new OutboxPartitionManager(self::$pdo);
        $this->store = new PdoOutboxStore(self::$pdo, static::platform());
    }

    public function testEnsureCreatesOnePartitionPerDayAheadAndIsIdempotent(): void
    {
        $today = self::day('2026-03-10');

        $names = $this->partitions->ensurePartitions(2, $today);
        $this->partitions->ensurePartitions(2, $today);

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
            $this->partitions->ensurePartitions(-1, self::day('2026-03-10'));
            self::fail('a negative daysAhead must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('daysAhead must be 0 or more, got -1', $error->getMessage());
        }

        self::assertSame([], $this->dailyPartitionNames());
    }

    public function testEnsurePartitionsCreatesThreeDaysAheadByDefault(): void
    {
        $names = $this->partitions->ensurePartitions(today: self::day('2026-03-10'));

        self::assertSame(
            ['outbox_messages_p20260310', 'outbox_messages_p20260311', 'outbox_messages_p20260312', 'outbox_messages_p20260313'],
            $names,
        );
        self::assertSame($names, $this->dailyPartitionNames());
    }

    public function testEnsureAttachesAStandaloneDailyTableThatIsNotAttachedYet(): void
    {
        self::$pdo->exec('CREATE TABLE outbox_messages_p20260310 (LIKE outbox_messages INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');

        $names = $this->partitions->ensurePartitions(0, self::day('2026-03-10'));

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
        $this->partitions->ensurePartitions(0, self::day('2026-03-10'));

        $this->insertAt('2026-03-10 13:00:00');

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages_p20260310'));
    }

    public function testInsertForADayWithNoPartitionFails(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('no partition of relation');

        $this->insertAt('2026-03-10 13:00:00');
    }

    public function testPurgeDropsExpiredPartitionsIncludingUnpublishedRows(): void
    {
        $this->partitions->ensurePartitions(3, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: true);  // expired → dropped
        $this->insertAt('2026-03-02 10:00:00', published: false); // expired, unpublished → dropped, reported
        $this->insertAt('2026-03-02 11:00:00', published: false);
        $this->insertAt('2026-03-04 10:00:00', published: false); // inside retention → untouched

        // today 2026-03-05, retention 2 days → cutoff 2026-03-03 00:00
        $report = $this->partitions->purge(2, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301', 'outbox_messages_p20260302'], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260302' => 2,
        ], $report->unpublished);
        self::assertSame(2, $report->unpublishedRowsDropped());
        self::assertSame(['outbox_messages_p20260303', 'outbox_messages_p20260304'], $this->dailyPartitionNames());
        self::assertSame(0, $this->standaloneDailyTableCount(), 'detached tables are dropped too');
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testDryRunReportsWithoutDropping(): void
    {
        $this->partitions->ensurePartitions(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: false);

        $report = $this->partitions->purge(1, dryRun: true, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301'], $report->dropped);
        self::assertSame(1, $report->unpublishedRowsDropped());
        self::assertSame(['outbox_messages_p20260301'], $this->dailyPartitionNames());
    }

    public function testPurgeFinishesAnInterruptedConcurrentDetach(): void
    {
        $this->partitions->ensurePartitions(0, self::day('2026-03-01'));

        // An open transaction that uses outbox_messages makes the second phase
        // of DETACH ... CONCURRENTLY wait; the timeout cancels it there, and
        // the partition stays "detach pending".
        $rival = self::newConnection();
        $rival->beginTransaction();
        $rival->query('SELECT COUNT(*) FROM outbox_messages');
        self::$pdo->exec("SET statement_timeout = '1s'");
        try {
            self::$pdo->exec('ALTER TABLE outbox_messages DETACH PARTITION outbox_messages_p20260301 CONCURRENTLY');
            self::fail('the detach must wait for the open transaction');
        } catch (PDOException) {
        } finally {
            self::$pdo->exec('RESET statement_timeout');
        }
        $rival->rollBack();
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM pg_inherits WHERE inhdetachpending'));

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

    public function testMaintainCommandCreatesPartitionsAndDropsExpiredUnpublishedRows(): void
    {
        $command = new CommandTester(new OutboxMaintainCommand($this->partitions));

        $command->execute([
            '--retention-days' => '1',
            '--premake-days' => '1',
        ]);
        $command->assertCommandIsSuccessful();
        self::assertContains('outbox_messages_p'.gmdate('Ymd'), $this->dailyPartitionNames());

        // An expired daily partition with an unpublished row: dropped, reported, exit code 0.
        $old = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 day')->setTime(0, 0);
        $this->partitions->ensurePartitions(0, $old);
        $this->insertAt($old->format('Y-m-d').' 10:00:00', published: false);

        $command->execute([
            '--retention-days' => '1',
        ]);

        $command->assertCommandIsSuccessful();
        self::assertStringContainsString('unpublished', $command->getDisplay());
        self::assertNotContains('outbox_messages_p'.$old->format('Ymd'), $this->dailyPartitionNames());
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

    private function insertAt(string $createdAtUtc, bool $published = false): void
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
