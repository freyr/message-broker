<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliverySchemaInstaller;
use PDOException;

final class PostgreSqlDeliverySchemaInstallerTest extends FunctionalTestCase
{
    public function testInstallCreatesTheTableTheIndexAndThePartitionsOfTheRange(): void
    {
        $this->dropDeliveries();

        (new PostgreSqlDeliverySchemaInstaller(self::$pdo))->install(2);

        self::assertSame('p', self::fetchString("SELECT relkind FROM pg_class WHERE relname = 'webhook_deliveries'"), 'a partitioned table');
        self::assertSame(3, $this->partitionCount(), 'today + 2 days ahead');
        self::assertSame(
            'CREATE INDEX idx_webhook_deliveries_due ON ONLY public.webhook_deliveries USING btree (lane, available_at) WHERE (completed_at IS NULL)',
            self::fetchString("SELECT pg_get_indexdef('idx_webhook_deliveries_due'::regclass)"),
        );
        self::assertSame(
            'PRIMARY KEY (id, created_at)',
            self::fetchString("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'webhook_deliveries'::regclass AND contype = 'p'"),
        );
    }

    public function testInstallCreatesTheRateLimitTableWithFillfactor50AndNoIndexBesidesTheKey(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS webhook_rate_limit');

        (new PostgreSqlDeliverySchemaInstaller(self::$pdo))->install(1);

        self::assertSame('{fillfactor=50}', self::fetchString("SELECT reloptions FROM pg_class WHERE relname = 'webhook_rate_limit'"));
        self::assertSame(
            'CREATE UNIQUE INDEX webhook_rate_limit_pkey ON public.webhook_rate_limit USING btree (name)',
            self::fetchString("SELECT string_agg(indexdef, '; ') FROM pg_indexes WHERE tablename = 'webhook_rate_limit'"),
            'each update of tokens and refilled_at can be a HOT update',
        );
    }

    public function testInstallCreatesTheLanesTableWithTheLaneAsTheKey(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS webhook_lanes');

        (new PostgreSqlDeliverySchemaInstaller(self::$pdo))->install(1);

        self::assertSame(
            'lane, window_started_at, requests, failures, open_until, opened_count, consecutive_openings, blocked_at',
            self::fetchString("SELECT string_agg(column_name, ', ' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'webhook_lanes'"),
        );
        self::assertSame(
            'PRIMARY KEY (lane)',
            self::fetchString("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'webhook_lanes'::regclass AND contype = 'p'"),
        );
    }

    public function testInstallKeepsTheRowsOfTheRateLimitAndLanesTables(): void
    {
        $installer = new PostgreSqlDeliverySchemaInstaller(self::$pdo);
        $installer->install(1);
        self::$pdo->exec("INSERT INTO webhook_rate_limit (name, tokens, refilled_at) VALUES ('', 1, now())");
        self::$pdo->exec("INSERT INTO webhook_lanes (lane, window_started_at) VALUES ('crm', now())");

        $installer->install(1);

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_rate_limit'));
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_lanes'));
    }

    public function testInstallTwiceIsSafe(): void
    {
        $this->dropDeliveries();
        $installer = new PostgreSqlDeliverySchemaInstaller(self::$pdo);

        $installer->install(1);
        $installer->install(1);
        $installer->install(3);

        self::assertSame(4, $this->partitionCount());
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_indexes WHERE indexname = 'idx_webhook_deliveries_due'"));
    }

    public function testInstallKeepsExistingRows(): void
    {
        $installer = new PostgreSqlDeliverySchemaInstaller(self::$pdo);
        $installer->install(1);
        $this->insertRow('now()');

        $installer->install(1);

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries'));
    }

    public function testTheDumpedSqlBuildsTheSameSchemaOnAnEmptyDatabase(): void
    {
        $this->dropDeliveries();

        foreach ((new PostgreSqlDeliverySchemaInstaller(self::$pdo))->sql(1) as $statement) {
            self::$pdo->exec($statement);
        }

        self::assertSame(2, $this->partitionCount());
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_indexes WHERE indexname = 'idx_webhook_deliveries_due'"));
    }

    public function testAnInsertForADayWithNoPartitionFails(): void
    {
        (new PostgreSqlDeliverySchemaInstaller(self::$pdo))->install(1);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('no partition of relation');

        $this->insertRow("now() - interval '30 days'");
    }

    private function insertRow(string $createdAt): void
    {
        self::$pdo->exec(
            "INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at)
             VALUES (gen_random_uuid(), gen_random_uuid(), 'lane', 'm', '\\x7b7d', '{}', {$createdAt}, now())",
        );
    }

    private function partitionCount(): int
    {
        return self::fetchInt("SELECT COUNT(*) FROM pg_inherits WHERE inhparent = 'webhook_deliveries'::regclass");
    }

    private static function fetchString(string $sql): string
    {
        $statement = self::$pdo->query($sql);
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }

    private function dropDeliveries(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS webhook_deliveries');
    }
}
