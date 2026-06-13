<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer\Avro;

use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use PHPUnit\Framework\TestCase;

final class FileSchemaStoreTest extends TestCase
{
    private const string SCHEMA_PATH = __DIR__.'/../../../Fixtures/schemas/order_placed.avsc';

    public function testLoadsAndParsesCommittedSchema(): void
    {
        $store = new FileSchemaStore([
            'order.placed' => self::SCHEMA_PATH,
        ]);

        $schema = $store->schemaFor('order.placed');

        self::assertInstanceOf(AvroSchema::class, $schema);
        self::assertStringContainsString('"OrderPlaced"', $store->schemaJsonFor('order.placed'));
    }

    public function testParsesEachSchemaOnlyOnce(): void
    {
        $store = new FileSchemaStore([
            'order.placed' => self::SCHEMA_PATH,
        ]);

        self::assertSame($store->schemaFor('order.placed'), $store->schemaFor('order.placed'));
    }

    public function testUnmappedMessageNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("No Avro schema mapped for message 'order.unknown'");

        new FileSchemaStore([])->schemaFor('order.unknown');
    }

    public function testMalformedSchemaFileThrowsDomainException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not valid');

        new FileSchemaStore([
            'order.malformed' => __DIR__.'/../../../Fixtures/schemas/malformed.avsc',
        ])->schemaFor('order.malformed');
    }

    public function testUnreadableFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        new FileSchemaStore([
            'order.placed' => '/nonexistent/order_placed.avsc',
        ])->schemaFor('order.placed');
    }
}
