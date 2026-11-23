<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Console\WebhookAbandonCommand;
use Freyr\MessageBroker\Console\WebhookLanesCommand;
use Freyr\MessageBroker\Console\WebhookMaintainCommand;
use Freyr\MessageBroker\Console\WebhookSetupCommand;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlCircuitBreaker;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliverySchemaInstaller;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryStore;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/** message-broker:webhook:setup, maintain, abandon and lanes on the PostgreSQL adapter. */
final class WebhookCommandsTest extends FunctionalTestCase
{
    public function testSetupCreatesTheTableAndThePartitionsAhead(): void
    {
        self::$pdo->exec('DROP TABLE webhook_deliveries');
        $tester = new CommandTester(new WebhookSetupCommand(new PostgreSqlDeliverySchemaInstaller(self::$pdo)));

        $status = $tester->execute([
            '--premake-days' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        $twoDaysAhead = self::utcDay('+2 day')->format('Ymd');
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relname = 'webhook_deliveries' AND relkind = 'p'"));
        self::assertContains("webhook_deliveries_p{$twoDaysAhead}", self::partitionNames());
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relname = 'idx_webhook_deliveries_due'"));
    }

    public function testLanesListsEachLaneAndCloseReturnsAnOpenAndABlockedLane(): void
    {
        $breaker = new PostgreSqlCircuitBreaker(self::$pdo, new CircuitBreakerPolicy(minimumRequests: 1, openMs: 600_000, maxConsecutiveOpenings: 1));
        $breaker->record([new LaneCounts('crm', 1, 1), new LaneCounts('erp', 1, 1)]);
        self::$pdo->exec("UPDATE webhook_lanes SET open_until = window_started_at WHERE lane = 'erp'");
        $breaker->record([new LaneCounts('erp', 1, 1)]);
        $tester = new CommandTester(new WebhookLanesCommand($breaker));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/\\| crm +\\| open +\\|.*\\| 1 +\\| 1 +\\|\\n/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/\\| erp +\\| blocked +\\|.*\\| 2 +\\| 2 +\\|\\n/', $tester->getDisplay());
        self::assertSame(['crm', 'erp'], $breaker->openLanes());

        self::assertSame(Command::SUCCESS, (new CommandTester(new WebhookLanesCommand($breaker)))->execute([
            '--close' => 'crm',
        ]));
        self::assertSame(Command::SUCCESS, (new CommandTester(new WebhookLanesCommand($breaker)))->execute([
            '--close' => 'erp',
        ]));

        self::assertSame([], $breaker->openLanes(), 'the next claim of a worker includes both lanes');
    }

    public function testSetupIsSafeToRepeat(): void
    {
        $command = new WebhookSetupCommand(new PostgreSqlDeliverySchemaInstaller(self::$pdo));
        self::insertAt(self::utcDay('today')->format('Y-m-d 10:00:00'), finished: false);

        self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute([]));
        self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute([]));

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'), 'a repeat keeps the rows');
    }

    public function testMaintainDryRunExitsWithZeroAndChangesNothing(): void
    {
        $expired = self::utcDay('-30 day');
        (new PostgreSqlDeliveryRetention(self::$pdo))->ensureCapacity(1, $expired);
        self::insertAt($expired->format('Y-m-d 10:00:00'), finished: true);
        self::insertAt($expired->modify('+1 day')->format('Y-m-d 10:00:00'), finished: false);
        $partitionsBefore = self::partitionNames();
        $tester = new CommandTester(new WebhookMaintainCommand(new PostgreSqlDeliveryRetention(self::$pdo)));

        $status = $tester->execute([
            '--dry-run' => true,
            '--premake-days' => '5',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($partitionsBefore, self::partitionNames(), 'no partition is created or dropped');
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'));
        $display = $tester->getDisplay();
        self::assertStringContainsString('[dry-run] Dropped webhook_deliveries_p'.$expired->format('Ymd').'.', $display);
        self::assertStringContainsString(
            '[dry-run] webhook_deliveries_p'.$expired->modify('+1 day')->format('Ymd').' would be kept: it holds 1 unfinished row(s)',
            $display,
        );
    }

    public function testMaintainDropsAFinishedExpiredPartitionKeepsOneWithAnUnfinishedRowAndFails(): void
    {
        $expired = self::utcDay('-30 day');
        (new PostgreSqlDeliveryRetention(self::$pdo))->ensureCapacity(1, $expired);
        self::insertAt($expired->format('Y-m-d 10:00:00'), finished: true);
        self::insertAt($expired->modify('+1 day')->format('Y-m-d 10:00:00'), finished: false);
        $tester = new CommandTester(new WebhookMaintainCommand(new PostgreSqlDeliveryRetention(self::$pdo)));

        $status = $tester->execute([
            '--premake-days' => '4',
        ]);

        self::assertSame(Command::FAILURE, $status);
        $partitions = self::partitionNames();
        self::assertNotContains('webhook_deliveries_p'.$expired->format('Ymd'), $partitions);
        self::assertContains('webhook_deliveries_p'.$expired->modify('+1 day')->format('Ymd'), $partitions);
        self::assertContains('webhook_deliveries_p'.self::utcDay('+4 day')->format('Ymd'), $partitions);
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'));
    }

    public function testAbandonEndsTheOldRowsSoThatMaintainDropsTheirPartition(): void
    {
        $old = self::utcDay('-30 day');
        $before = $old->modify('+1 day')->format('Y-m-d');
        (new PostgreSqlDeliveryRetention(self::$pdo))->ensureCapacity(1, $old);
        self::insertAt($old->format('Y-m-d 10:00:00'), finished: false);
        self::insertAt($old->format('Y-m-d 11:00:00'), finished: false, lane: 'removed');
        self::insertAt($old->format('Y-m-d 12:00:00'), finished: false, lane: 'removed');
        self::insertAt($old->format('Y-m-d 13:00:00'), finished: true);
        $maintain = new CommandTester(new WebhookMaintainCommand(new PostgreSqlDeliveryRetention(self::$pdo)));
        self::assertSame(Command::FAILURE, $maintain->execute([]), 'the partition holds unfinished rows: it is kept');

        $store = new PostgreSqlDeliveryStore(self::$pdo);
        $dryRun = new CommandTester(new WebhookAbandonCommand($store));
        self::assertSame(Command::SUCCESS, $dryRun->execute([
            '--before' => $before,
            '--dry-run' => true,
        ]));
        self::assertSame(3, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries WHERE completed_at IS NULL'), 'the dry run changes nothing');
        $run = new CommandTester(new WebhookAbandonCommand($store, batchSize: 1));
        self::assertSame(Command::SUCCESS, $run->execute([
            '--before' => $before,
        ]));

        self::assertSame(
            "Unfinished deliveries created before {$before} 00:00:00.000 UTC:\n"
            ."commands: 1 row(s) abandoned.\n"
            ."removed: 2 row(s) abandoned.\n"
            ."Total: 3 row(s) abandoned.\n",
            $run->getDisplay(),
        );
        self::assertSame(
            str_replace(['[dry-run] ', 'would be abandoned'], ['', 'abandoned'], $dryRun->getDisplay()),
            $run->getDisplay(),
            'the run moves the counts of the dry run',
        );
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries WHERE completed_at IS NULL'));
        self::assertSame(3, self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE error_class = 'abandoned'"));
        self::assertSame(Command::SUCCESS, $maintain->execute([]), 'each row is finished: the partition is dropped');
        self::assertNotContains('webhook_deliveries_p'.$old->format('Ymd'), self::partitionNames());
    }

    private static function insertAt(string $createdAtUtc, bool $finished, string $lane = 'commands'): void
    {
        $id = Uuid::v7()->toString();
        $completedAt = $finished ? "'{$createdAtUtc}'" : 'NULL';
        $outcome = $finished ? '1' : 'NULL';
        self::$pdo->exec(
            "INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at, completed_at, outcome)
             VALUES ('{$id}', '{$id}', '{$lane}', 'order.placed', '\\x7b7d', '{}', '{$createdAtUtc}', '{$createdAtUtc}', {$completedAt}, {$outcome})",
        );
    }

    /** @return list<string> the daily partitions attached to webhook_deliveries */
    private static function partitionNames(): array
    {
        $statement = self::$pdo->query(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'webhook_deliveries'::regclass ORDER BY c.relname",
        );
        self::assertNotFalse($statement);

        /** @var list<string> */
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    private static function utcDay(string $modifier): DateTimeImmutable
    {
        return (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify($modifier);
    }
}
