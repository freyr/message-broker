<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeliverySchemaInstaller;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgreSqlDeliverySchemaInstallerTest extends TestCase
{
    public function testTheTableHasTheColumnsAndTheKeyOfTheSpecAndOneRangePartitionForEachDay(): void
    {
        self::assertSame(
            'CREATE TABLE IF NOT EXISTS webhook_deliveries ('
            .' id UUID NOT NULL,'
            .' message_id UUID NOT NULL,'
            .' lane VARCHAR(64) NOT NULL,'
            .' message_name VARCHAR(255) NOT NULL,'
            .' body BYTEA NOT NULL,'
            .' headers JSONB NOT NULL,'
            .' created_at TIMESTAMP(3) NOT NULL,'
            .' available_at TIMESTAMP(3) NOT NULL,'
            .' attempts INTEGER NOT NULL DEFAULT 0,'
            .' completed_at TIMESTAMP(3) NULL,'
            .' outcome SMALLINT NULL,'
            .' PRIMARY KEY (id, created_at)'
            .' ) PARTITION BY RANGE (created_at)',
            self::normalized($this->installer()->sql(0)[0]),
        );
    }

    public function testThePartialIndexServesTheClaimOfTheUnfinishedRowsOfALane(): void
    {
        self::assertSame(
            'CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_due ON webhook_deliveries (lane, available_at) WHERE completed_at IS NULL',
            self::normalized($this->installer()->sql(0)[1]),
        );
    }

    public function testSqlListsTheTableAndTheIndexBeforeTheDailyPartitionsOfTodayAndTheDaysAhead(): void
    {
        $statements = $this->installer()->sql(2);

        self::assertCount(2 + 3 * 2, $statements, 'the table and the index, then CREATE and ATTACH for each of 3 days');
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        foreach ([0, 1, 2] as $offset) {
            $day = $today->modify("+{$offset} day");
            $name = 'webhook_deliveries_p'.$day->format('Ymd');
            self::assertStringStartsWith(sprintf('CREATE TABLE IF NOT EXISTS "%s"', $name), $statements[2 + 2 * $offset]);
            self::assertStringStartsWith(sprintf('ALTER TABLE webhook_deliveries ATTACH PARTITION "%s"', $name), $statements[3 + 2 * $offset]);
        }
        self::assertStringNotContainsString('PARTITION OF', implode("\n", $statements), 'no DEFAULT partition: retention detaches concurrently');
    }

    public function testSqlWithNoDaysAheadHoldsTheTableAndTodaysPartitionOnly(): void
    {
        self::assertCount(2 + 2, $this->installer()->sql(0));
    }

    public function testSqlRejectsNegativeDaysAhead(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->installer()->sql(-1);
    }

    public function testSqlHoldsNoOtherTable(): void
    {
        $ddl = implode("\n", $this->installer()->sql(0));

        self::assertStringNotContainsString('outbox_messages', $ddl);
        self::assertStringNotContainsString('dead_letters', $ddl);
        self::assertStringNotContainsString('url', strtolower($ddl), 'the table holds no URL');
        self::assertStringNotContainsString('secret', strtolower($ddl), 'and no secret');
    }

    private static function normalized(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($sql));
    }

    private function installer(): PostgreSqlDeliverySchemaInstaller
    {
        return new PostgreSqlDeliverySchemaInstaller($this->createStub(PDO::class));
    }
}
