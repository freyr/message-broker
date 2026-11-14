<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Storage;

use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;
use PHPUnit\Framework\TestCase;

final class PostgreSqlPlatformTest extends TestCase
{
    public function testSchemaSqlBuildsPostgresDdl(): void
    {
        $ddl = implode("\n", (new PostgreSqlPlatform())->schemaSql(Format::Json));

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

    public function testOutboxHasNoKeyOrMetadataColumn(): void
    {
        $ddl = implode("\n", (new PostgreSqlPlatform())->schemaSql(Format::Json));

        self::assertStringNotContainsString('message_key', $ddl);
        self::assertStringNotContainsString('metadata', $ddl);
    }

    public function testBodyIsByteaForBothFormats(): void
    {
        foreach ([Format::Json, Format::Avro] as $format) {
            $ddl = implode("\n", (new PostgreSqlPlatform())->schemaSql($format));
            self::assertStringContainsString('body BYTEA NOT NULL', $ddl);
        }
    }

    public function testReadBodyReadsStreamResource(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\x00\x01binary");
        rewind($stream);

        self::assertSame("\x00\x01binary", (new PostgreSqlPlatform())->readBody($stream));
        fclose($stream);
    }

    public function testReadBodyPassesThroughString(): void
    {
        self::assertSame('{"a":1}', (new PostgreSqlPlatform())->readBody('{"a":1}'));
    }

    public function testSelectClaimBatchSqlSkipsLockedUnpublishedDueRows(): void
    {
        $sql = (new PostgreSqlPlatform())->selectClaimBatchSql();

        self::assertStringContainsString(
            'WHERE lane = :lane AND published_at IS NULL AND available_at <= :now',
            $sql,
        );
        self::assertStringContainsString('ORDER BY available_at', $sql);
        self::assertStringContainsString('LIMIT :limit', $sql);
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $sql);
    }
}
