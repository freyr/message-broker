<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Avro;

use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Serializer\Avro\HttpSchemaRegistry;
use Freyr\MessageBroker\Serializer\Avro\RegistryUnavailable;
use Freyr\MessageBroker\Serializer\Avro\SchemaNotFound;
use PHPUnit\Framework\TestCase;

/** Runs against the real Apicurio container (Confluent-compat API). */
final class HttpSchemaRegistryTest extends TestCase
{
    use RegistersSchemas;

    private static string $schemaJson;

    public static function setUpBeforeClass(): void
    {
        $schemaJson = file_get_contents(__DIR__.'/../../Fixtures/schemas/order_placed.avsc');
        self::assertNotFalse($schemaJson);
        self::$schemaJson = $schemaJson;
        self::registerSchema('order.placed', self::$schemaJson);
    }

    public function testLooksUpIdOfRegisteredSchema(): void
    {
        $registry = new HttpSchemaRegistry(self::registryUrl());

        $id = $registry->idFor('order.placed', self::$schemaJson);

        self::assertGreaterThan(0, $id);
    }

    public function testFetchesWriterSchemaById(): void
    {
        $registry = new HttpSchemaRegistry(self::registryUrl());
        $id = $registry->idFor('order.placed', self::$schemaJson);

        $schema = $registry->schemaById($id);

        self::assertInstanceOf(AvroSchema::class, $schema);
        self::assertStringContainsString('OrderPlaced', (string) $schema);
    }

    public function testUnknownSubjectThrowsSchemaNotFound(): void
    {
        $this->expectException(SchemaNotFound::class);

        new HttpSchemaRegistry(self::registryUrl())->idFor('order.never_registered', self::$schemaJson);
    }

    public function testUnknownIdThrowsSchemaNotFound(): void
    {
        $this->expectException(SchemaNotFound::class);

        new HttpSchemaRegistry(self::registryUrl())->schemaById(999_999_999);
    }

    public function testUnreachableRegistryThrowsRegistryUnavailable(): void
    {
        $this->expectException(RegistryUnavailable::class);

        new HttpSchemaRegistry('http://apicurio:9', timeoutSec: 1.0)
            ->schemaById(1);
    }
}
