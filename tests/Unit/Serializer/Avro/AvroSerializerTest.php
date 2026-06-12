<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer\Avro;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Apache\Avro\IO\AvroStringIO;
use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Serializer\Avro\AvroSerializer;
use Freyr\MessageBroker\Serializer\Avro\ConfluentFrame;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Tests\Fixtures\StubSchemaRegistry;
use PHPUnit\Framework\TestCase;

final class AvroSerializerTest extends TestCase
{
    private const string SCHEMA_PATH = __DIR__.'/../../../Fixtures/schemas/order_placed.avsc';

    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'metadata' => [
                'message_name' => 'order.placed',
                'message_id' => '0190d2f3-4a5b-7c8d-9e0f-1a2b3c4d5e6f',
                'created_at' => 1_749_722_400_123,
            ],
            'payload' => [
                'order_id' => 'o-42',
                'total_cents' => 12_500,
            ],
        ];
    }

    private function serializer(): AvroSerializer
    {
        return new AvroSerializer(
            schemas: new FileSchemaStore([
                'order.placed' => self::SCHEMA_PATH,
            ]),
            registry: new StubSchemaRegistry(schemas: [], idForAnySubject: 42),
        );
    }

    public function testEncodesPayloadOnlyIntoConfluentFrame(): void
    {
        $wire = $this->serializer()
            ->serialize($this->document());

        $frame = ConfluentFrame::parse($wire->bytes);
        self::assertSame(42, $frame->schemaId);

        $schemaJson = file_get_contents(self::SCHEMA_PATH);
        self::assertNotFalse($schemaJson);
        $schema = AvroSchema::parse($schemaJson);
        self::assertInstanceOf(AvroSchema::class, $schema);
        $decoded = new AvroIODatumReader($schema, $schema)
            ->read(new AvroIOBinaryDecoder(new AvroStringIO($frame->avroBytes)));

        self::assertIsArray($decoded);
        self::assertSame([
            'order_id' => 'o-42',
            'total_cents' => 12_500,
        ], $decoded, 'body carries the payload record only — no envelope');
    }

    public function testEmitsMetadataAsTransportHeaders(): void
    {
        $wire = $this->serializer()
            ->serialize($this->document());

        self::assertSame('avro/binary', $wire->contentType);
        self::assertSame([
            'x-message-id' => '0190d2f3-4a5b-7c8d-9e0f-1a2b3c4d5e6f',
            'x-message-name' => 'order.placed',
            'x-created-at' => 1_749_722_400_123,
        ], $wire->headers);
    }

    public function testDocumentWithoutSectionsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->serializer()
            ->serialize([
                'payload' => [],
            ]);
    }
}
