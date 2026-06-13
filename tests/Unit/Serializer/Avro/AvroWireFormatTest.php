<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer\Avro;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Apache\Avro\Datum\AvroIOTypeException;
use Apache\Avro\IO\AvroStringIO;
use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Serializer\Avro\AvroWireFormat;
use Freyr\MessageBroker\Serializer\Avro\ConfluentFrame;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Tests\Fixtures\StubSchemaRegistry;
use PHPUnit\Framework\TestCase;

final class AvroWireFormatTest extends TestCase
{
    private const string SCHEMA_PATH = __DIR__.'/../../../Fixtures/schemas/order_placed.avsc';

    private function format(): AvroWireFormat
    {
        return new AvroWireFormat(
            schemas: new FileSchemaStore([
                'order.placed' => self::SCHEMA_PATH,
            ]),
            registry: new StubSchemaRegistry(schemas: [], idForAnySubject: 42),
        );
    }

    public function testEncodesPayloadOnlyIntoConfluentFrame(): void
    {
        $format = $this->format();
        self::assertSame('avro/binary', $format->contentType());

        $body = $format->encode('order.placed', [
            'order_id' => 'o-42',
            'total_cents' => 12_500,
        ]);

        $frame = ConfluentFrame::parse($body);
        self::assertSame(42, $frame->schemaId);

        $schemaJson = file_get_contents(self::SCHEMA_PATH);
        self::assertNotFalse($schemaJson);
        $schema = AvroSchema::parse($schemaJson);
        self::assertInstanceOf(AvroSchema::class, $schema);
        $decoded = new AvroIODatumReader($schema, $schema)
            ->read(new AvroIOBinaryDecoder(new AvroStringIO($frame->avroBytes)));

        self::assertSame([
            'order_id' => 'o-42',
            'total_cents' => 12_500,
        ], $decoded);
    }

    public function testNonConformingPayloadThrowsAvroIoTypeExceptionBeforeTouchingTheRegistry(): void
    {
        // No registry id is configured to be needed: encode validates the
        // payload FIRST, so a bad payload throws even on a cold cache.
        $format = new AvroWireFormat(
            schemas: new FileSchemaStore([
                'order.placed' => self::SCHEMA_PATH,
            ]),
            registry: new StubSchemaRegistry(schemas: [], idForAnySubject: 42),
        );

        $this->expectException(AvroIOTypeException::class);

        $format->encode('order.placed', [
            'order_id' => 'o-1',
            // total_cents missing — does not conform to the OrderPlaced schema.
        ]);
    }
}
