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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Partition retention: daily partitions are created ahead; an expired
 * partition is dropped only when every row in it is published; expired
 * published rows in the DEFAULT partition are deleted.
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

    public function testRowsLandInTheirDailyPartition(): void
    {
        $this->partitions->ensurePartitions(0, self::day('2026-03-10'));

        $this->insertAt('2026-03-10 13:00:00');

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages_p20260310'));
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages_default'));
    }

    public function testPurgeDropsOnlyExpiredFullyPublishedPartitions(): void
    {
        $this->partitions->ensurePartitions(3, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: true);  // expired, published → dropped
        $this->insertAt('2026-03-02 10:00:00', published: false); // expired, pending → kept
        $this->insertAt('2026-03-04 10:00:00', published: true);  // inside retention → untouched

        // today 2026-03-05, retention 2 days → cutoff 2026-03-03 00:00
        $report = $this->partitions->purge(2, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301'], $report->dropped);
        self::assertSame([
            'outbox_messages_p20260302' => 1,
        ], $report->kept);
        self::assertTrue($report->hasStuckRows());
        self::assertSame(
            ['outbox_messages_p20260302', 'outbox_messages_p20260303', 'outbox_messages_p20260304'],
            $this->dailyPartitionNames(),
        );
    }

    public function testDryRunReportsWithoutDropping(): void
    {
        $this->partitions->ensurePartitions(0, self::day('2026-03-01'));
        $this->insertAt('2026-03-01 10:00:00', published: true);

        $report = $this->partitions->purge(1, dryRun: true, today: self::day('2026-03-05'));

        self::assertSame(['outbox_messages_p20260301'], $report->dropped);
        self::assertSame(['outbox_messages_p20260301'], $this->dailyPartitionNames());
    }

    public function testPurgeDeletesExpiredPublishedRowsFromTheDefaultPartition(): void
    {
        // No daily partitions: every row lands in DEFAULT.
        $this->insertAt('2026-03-01 10:00:00', published: true);  // expired, published → deleted
        $this->insertAt('2026-03-01 11:00:00', published: false); // expired, pending → stays
        $this->insertAt('2026-03-04 10:00:00', published: true);  // inside retention → stays

        $report = $this->partitions->purge(2, today: self::day('2026-03-05'));

        self::assertSame(1, $report->defaultRowsDeleted);
        self::assertSame(2, $report->defaultRowsRemaining);
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM outbox_messages_default'));
    }

    public function testMaintainCommandCreatesPartitionsAndFailsOnStuckRows(): void
    {
        $command = new CommandTester(new OutboxMaintainCommand($this->partitions));

        $command->execute([
            '--retention-days' => '1',
            '--premake-days' => '1',
        ]);
        $command->assertCommandIsSuccessful();
        self::assertContains('outbox_messages_p'.gmdate('Ymd'), $this->dailyPartitionNames());

        // An expired daily partition with an unpublished row: kept, exit code 1.
        $old = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 day')->setTime(0, 0);
        $this->partitions->ensurePartitions(0, $old);
        $this->insertAt($old->format('Y-m-d').' 10:00:00', published: false);

        $status = $command->execute([
            '--retention-days' => '1',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('unpublished', $command->getDisplay());
    }

    public function testMaintainCommandRejectsInvalidOptions(): void
    {
        $command = new CommandTester(new OutboxMaintainCommand($this->partitions));

        self::assertSame(Command::INVALID, $command->execute([
            '--retention-days' => '0',
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

    /** @return list<string> */
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

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
