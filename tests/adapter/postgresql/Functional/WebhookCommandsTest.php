<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeliveryRetention;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeliverySchemaInstaller;
use Freyr\MessageBroker\Console\WebhookMaintainCommand;
use Freyr\MessageBroker\Console\WebhookSetupCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/** message-broker:webhook:setup and message-broker:webhook:maintain on the PostgreSQL adapter. */
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

    private static function insertAt(string $createdAtUtc, bool $finished): void
    {
        $id = Uuid::v7()->toString();
        $completedAt = $finished ? "'{$createdAtUtc}'" : 'NULL';
        $outcome = $finished ? '1' : 'NULL';
        self::$pdo->exec(
            "INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at, completed_at, outcome)
             VALUES ('{$id}', '{$id}', 'commands', 'order.placed', '\\x7b7d', '{}', '{$createdAtUtc}', '{$createdAtUtc}', {$completedAt}, {$outcome})",
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
