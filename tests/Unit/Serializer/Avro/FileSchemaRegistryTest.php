<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer\Avro;

use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Serializer\Avro\DerivedSchemaId;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaRegistry;
use Freyr\MessageBroker\Serializer\Avro\SchemaNotFound;
use PHPUnit\Framework\TestCase;

final class FileSchemaRegistryTest extends TestCase
{
    private const string V1 = __DIR__.'/../../../Fixtures/schemas/order.placed/v1.avsc';
    private const string V2 = __DIR__.'/../../../Fixtures/schemas/order.placed/v2.avsc';

    private static function json(string $path): string
    {
        $json = file_get_contents($path);
        self::assertIsString($json);

        return $json;
    }

    public function testDerivedIdIsDeterministicAndInConfluentRange(): void
    {
        $json = self::json(self::V1);

        $id = DerivedSchemaId::fromSchemaJson($json);

        self::assertSame($id, DerivedSchemaId::fromSchemaJson($json));
        self::assertGreaterThanOrEqual(0, $id);
        self::assertLessThanOrEqual(0x7FFFFFFF, $id);
    }

    public function testResolvesADerivedIdBackToItsSchema(): void
    {
        $registry = new FileSchemaRegistry([self::V1]);

        $id = $registry->idFor('order.placed', self::json(self::V1));
        $schema = $registry->schemaById($id);

        self::assertInstanceOf(AvroSchema::class, $schema);
        self::assertStringContainsString('OrderPlaced', (string) $schema);
    }

    public function testRetainsEveryVersionUnderDistinctIds(): void
    {
        $registry = new FileSchemaRegistry([self::V1, self::V2]);

        $v1Id = $registry->idFor('order.placed', self::json(self::V1));
        $v2Id = $registry->idFor('order.placed', self::json(self::V2));

        self::assertNotSame($v1Id, $v2Id);
        self::assertStringContainsString('OrderPlaced', (string) $registry->schemaById($v1Id));
        self::assertStringContainsString('currency', (string) $registry->schemaById($v2Id));
        self::assertStringNotContainsString('currency', (string) $registry->schemaById($v1Id));
    }

    public function testDuplicateDerivedIdAcrossDistinctSchemasThrowsAtConstruction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('collision');

        // Force both distinct schemas to the same id — the collision guard.
        new FileSchemaRegistry([self::V1, self::V2], static fn (string $json): int => 42);
    }

    public function testIdenticalContentAtTheSameIdIsNotACollision(): void
    {
        $registry = new FileSchemaRegistry([self::V1, self::V1]);

        self::assertStringContainsString('OrderPlaced', (string) $registry->schemaById(
            $registry->idFor('order.placed', self::json(self::V1)),
        ));
    }

    public function testIdForAnUncommittedSchemaThrowsSchemaNotFound(): void
    {
        $registry = new FileSchemaRegistry([self::V1]);

        $this->expectException(SchemaNotFound::class);

        $registry->idFor('order.placed', self::json(self::V2));
    }

    public function testSchemaByIdForAnUnknownIdThrowsSchemaNotFound(): void
    {
        $registry = new FileSchemaRegistry([self::V1]);

        $this->expectException(SchemaNotFound::class);

        $registry->schemaById(123_456_789);
    }

    public function testUnreadableSchemaFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        new FileSchemaRegistry(['/nonexistent/v1.avsc']);
    }
}
