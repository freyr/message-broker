<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer\Avro;

use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Serializer\Avro\AvroDeserializer;
use Freyr\MessageBroker\Serializer\Avro\ConfluentFrame;
use Freyr\MessageBroker\Serializer\Avro\RegistryUnavailable;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Tests\Fixtures\StubSchemaRegistry;
use PHPUnit\Framework\TestCase;

final class AvroDeserializerTest extends TestCase
{
    private const string SCHEMA_PATH = __DIR__.'/../../../Fixtures/schemas/order_placed.avsc';

    private static AvroSchema $schema;

    public static function setUpBeforeClass(): void
    {
        $schemaJson = file_get_contents(self::SCHEMA_PATH);
        self::assertNotFalse($schemaJson);
        self::$schema = AvroSchema::parse($schemaJson);
    }

    /** @return array<string, int|string> */
    private function headers(): array
    {
        return [
            'x-message-id' => '0190d2f3-4a5b-7c8d-9e0f-1a2b3c4d5e6f',
            'x-message-name' => 'order.placed',
            'x-created-at' => 1_749_722_400_123,
        ];
    }

    private function framedBody(int $schemaId = 7): string
    {
        $io = new AvroStringIO();
        new AvroIODatumWriter(self::$schema)->write([
            'order_id' => 'o-42',
            'total_cents' => 12_500,
        ], new AvroIOBinaryEncoder($io));
        $bytes = $io->string();
        self::assertIsString($bytes);

        return new ConfluentFrame($schemaId, $bytes)
            ->bytes();
    }

    public function testDecodesFrameAndHeadersIntoIncomingMessage(): void
    {
        $deserializer = new AvroDeserializer(new StubSchemaRegistry(schemas: [
            7 => self::$schema,
        ]));

        $incoming = $deserializer->deserialize($this->framedBody(), $this->headers());

        self::assertSame('0190d2f3-4a5b-7c8d-9e0f-1a2b3c4d5e6f', $incoming->messageId);
        self::assertSame('order.placed', $incoming->messageName);
        self::assertSame(1_749_722_400_123, $incoming->createdAt);
        self::assertSame([
            'order_id' => 'o-42',
            'total_cents' => 12_500,
        ], $incoming->payload);
    }

    public function testMissingMetadataHeadersIsMalformed(): void
    {
        $deserializer = new AvroDeserializer(new StubSchemaRegistry(schemas: [
            7 => self::$schema,
        ]));

        $this->expectException(MalformedMessage::class);

        $deserializer->deserialize($this->framedBody(), [
            'x-message-id' => 'id-only',
        ]);
    }

    public function testBadMagicByteIsMalformed(): void
    {
        $deserializer = new AvroDeserializer(new StubSchemaRegistry(schemas: [
            7 => self::$schema,
        ]));

        $this->expectException(MalformedMessage::class);

        $deserializer->deserialize("\x01garbage", $this->headers());
    }

    public function testUndecodablePayloadIsMalformed(): void
    {
        $deserializer = new AvroDeserializer(new StubSchemaRegistry(schemas: [
            7 => self::$schema,
        ]));

        $this->expectException(MalformedMessage::class);

        $deserializer->deserialize(new ConfluentFrame(7, "\xFF")->bytes(), $this->headers());
    }

    public function testRegistryOutagePropagatesUntouched(): void
    {
        $deserializer = new AvroDeserializer(new StubSchemaRegistry(schemas: [], unavailable: true));

        // NOT MalformedMessage — must reach the consumer loop and requeue (A10).
        $this->expectException(RegistryUnavailable::class);

        $deserializer->deserialize($this->framedBody(), $this->headers());
    }
}
