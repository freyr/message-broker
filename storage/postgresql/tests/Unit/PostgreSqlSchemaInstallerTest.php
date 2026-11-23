<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlSchemaInstaller;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgreSqlSchemaInstallerTest extends TestCase
{
    public function testSqlBuildsPostgresDdl(): void
    {
        $ddl = implode("\n", $this->installer()->sql(0));

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS outbox_messages', $ddl);
        self::assertStringContainsString('id UUID NOT NULL', $ddl);
        self::assertStringContainsString('body BYTEA NOT NULL', $ddl);
        self::assertStringContainsString('created_at TIMESTAMP(3) NOT NULL', $ddl);
        self::assertStringContainsString('attempts INTEGER NOT NULL DEFAULT 0', $ddl);
        self::assertStringContainsString('published_at TIMESTAMP(3) NULL', $ddl);
        self::assertStringContainsString('PRIMARY KEY (id, created_at)', $ddl);
        self::assertStringContainsString(') PARTITION BY RANGE (created_at)', $ddl);
        self::assertStringNotContainsString('PARTITION OF', $ddl, 'no DEFAULT partition: retention detaches concurrently');
        self::assertStringContainsString('WHERE published_at IS NULL', $ddl);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS message_deduplication', $ddl);
        self::assertStringContainsString('idx_dedup_created_at ON message_deduplication (created_at)', $ddl);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS dead_letters', $ddl);
    }

    public function testDeadLettersHaveTheReplayableColumnAfterReplayedAt(): void
    {
        $deadLetters = $this->installer()->sql(0)[4];

        self::assertStringContainsString(
            "replayed_at TIMESTAMP(3) NULL,\n    replayable BOOLEAN NOT NULL DEFAULT TRUE\n)",
            $deadLetters,
        );
    }

    public function testOutboxHasNoKeyOrMetadataColumn(): void
    {
        $ddl = implode("\n", $this->installer()->sql(0));

        self::assertStringNotContainsString('message_key', $ddl);
        self::assertStringNotContainsString('metadata', $ddl);
    }

    public function testBodyIsBytea(): void
    {
        $ddl = implode("\n", $this->installer()->sql(0));
        self::assertStringContainsString('body BYTEA NOT NULL', $ddl);
    }

    public function testSqlListsTheTablesBeforeTheDailyPartitionsOfTodayAndTheDaysAhead(): void
    {
        $statements = $this->installer()->sql(2);

        self::assertCount(6 + 3 * 2, $statements, 'six table and index statements, then CREATE and ATTACH for each of 3 days');
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS outbox_messages', $statements[0]);
        self::assertStringStartsWith('CREATE INDEX IF NOT EXISTS idx_outbox_pending', $statements[1]);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS message_deduplication', $statements[2]);
        self::assertStringStartsWith('CREATE INDEX IF NOT EXISTS idx_dedup_created_at', $statements[3]);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS dead_letters', $statements[4]);
        self::assertStringStartsWith('CREATE UNIQUE INDEX IF NOT EXISTS uq_dead_letters_open', $statements[5]);

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        foreach ([0, 1, 2] as $offset) {
            $day = $today->modify("+{$offset} day");
            $name = 'outbox_messages_p'.$day->format('Ymd');
            self::assertStringStartsWith(sprintf('CREATE TABLE IF NOT EXISTS "%s"', $name), $statements[6 + 2 * $offset]);
            self::assertStringStartsWith(sprintf('ALTER TABLE outbox_messages ATTACH PARTITION "%s"', $name), $statements[7 + 2 * $offset]);
        }
    }

    public function testSqlWithNoDaysAheadHoldsTheTablesAndTodaysPartitionOnly(): void
    {
        self::assertCount(6 + 2, $this->installer()->sql(0));
    }

    public function testSqlRejectsNegativeDaysAhead(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->installer()->sql(-1);
    }

    public function testDeadLettersHaveAPartialUniqueIndexOnTheOpenPair(): void
    {
        self::assertSame(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_dead_letters_open ON dead_letters (message_id, source) WHERE replayed_at IS NULL',
            preg_replace('/\s+/', ' ', trim($this->installer()->sql(0)[5])),
        );
    }

    private function installer(): PostgreSqlSchemaInstaller
    {
        return new PostgreSqlSchemaInstaller($this->createStub(PDO::class));
    }
}
