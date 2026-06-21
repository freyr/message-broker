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
        self::assertStringContainsString('metadata JSONB NOT NULL', $ddl);
        self::assertStringContainsString('body BYTEA NOT NULL', $ddl);
        self::assertStringContainsString('created_at TIMESTAMP(3) NOT NULL', $ddl);
        self::assertStringContainsString(
            'CREATE INDEX IF NOT EXISTS idx_outbox_drain ON outbox_messages (lane, id)',
            $ddl,
        );
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS message_deduplication', $ddl);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS dead_letters', $ddl);
    }

    public function testBodyIsByteaForBothFormats(): void
    {
        foreach ([Format::Json, Format::Avro] as $format) {
            $ddl = implode("\n", (new PostgreSqlPlatform())->schemaSql($format));
            self::assertStringContainsString('body BYTEA NOT NULL', $ddl);
        }
    }
}
