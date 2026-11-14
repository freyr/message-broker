<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer\Avro;

use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use Apache\Avro\Schema\AvroSchema;
use Freyr\MessageBroker\Serializer\Avro\AvroDeserializer;
use Freyr\MessageBroker\Serializer\Avro\ConfluentFrame;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Avro\SchemaNotFound;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Tests\Fixtures\StubSchemaRegistry;
use PHPUnit\Framework\TestCase;

final class AvroDeserializerTest extends TestCase
{
    private const string V1_PATH = __DIR__.'/../../../Fixtures/schemas/order.placed/v1.avsc';
    private const string V2_PATH = __DIR__.'/../../../Fixtures/schemas/order.placed/v2.avsc';

    private static AvroSchema $v1;

    public static function setUpBeforeClass(): void
    {
        $json = file_get_contents(self::V1_PATH);
        self::assertNotFalse($json);
        self::$v1 = AvroSchema::parse($json);
    }

    /** Reader-schema source mapping order.placed to the given committed schema. */
    private function readerStore(string $path = self::V1_PATH): FileSchemaStore
    {
        return new FileSchemaStore([
            'order.placed' => $path,
        ]);
    }

    /** @return array<string, int|string> */
    private function headers(): array
    {
        return [
            MetadataHeader::MESSAGE_ID => '0190d2f3-4a5b-7c8d-9e0f-1a2b3c4d5e6f',
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::CREATED_AT => 1_749_722_400_123,
        ];
    }

    private function framedBody(int $schemaId = 7): string
    {
        $io = new AvroStringIO();
        new AvroIODatumWriter(self::$v1)->write([
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
        $deserializer = new AvroDeserializer(
            new StubSchemaRegistry(schemas: [
                7 => self::$v1,
            ]),
            $this->readerStore(),
        );

        $incoming = $deserializer->deserialize($this->framedBody(), $this->headers());

        self::assertSame('0190d2f3-4a5b-7c8d-9e0f-1a2b3c4d5e6f', $incoming->messageId);
        self::assertSame('order.placed', $incoming->messageName);
        self::assertSame(1_749_722_400_123, $incoming->createdAt);
        self::assertSame([
            'order_id' => 'o-42',
            'total_cents' => 12_500,
        ], $incoming->payload);
    }

    public function testResolvesOlderWriterAgainstNewerReaderFillingDefaults(): void
    {
        // Writer wrote with v1 (no `currency`); this consumer reads with v2,
        // which adds `currency` with a "USD" default. Avro resolution fills it.
        $deserializer = new AvroDeserializer(
            new StubSchemaRegistry(schemas: [
                7 => self::$v1,
            ]),
            $this->readerStore(self::V2_PATH),
        );

        $incoming = $deserializer->deserialize($this->framedBody(), $this->headers());

        self::assertSame([
            'order_id' => 'o-42',
            'total_cents' => 12_500,
            'currency' => 'USD',
        ], $incoming->payload);
    }

    public function testMissingMetadataHeadersIsMalformed(): void
    {
        $deserializer = new AvroDeserializer(
            new StubSchemaRegistry(schemas: [
                7 => self::$v1,
            ]),
            $this->readerStore(),
        );

        $this->expectException(MalformedMessage::class);

        $deserializer->deserialize($this->framedBody(), [
            'x-message-id' => 'id-only',
        ]);
    }

    public function testBadMagicByteIsMalformed(): void
    {
        $deserializer = new AvroDeserializer(
            new StubSchemaRegistry(schemas: [
                7 => self::$v1,
            ]),
            $this->readerStore(),
        );

        $this->expectException(MalformedMessage::class);

        $deserializer->deserialize("\x01garbage", $this->headers());
    }

    public function testUndecodablePayloadIsMalformed(): void
    {
        $deserializer = new AvroDeserializer(
            new StubSchemaRegistry(schemas: [
                7 => self::$v1,
            ]),
            $this->readerStore(),
        );

        $this->expectException(MalformedMessage::class);

        $deserializer->deserialize(new ConfluentFrame(7, "\xFF")->bytes(), $this->headers());
    }

    public function testSchemaResolutionFailurePropagatesUntouched(): void
    {
        // Writer id absent from the committed set — NOT MalformedMessage; must
        // reach the consumer loop and requeue (A10).
        $deserializer = new AvroDeserializer(new StubSchemaRegistry(schemas: []), $this->readerStore());

        $this->expectException(SchemaNotFound::class);

        $deserializer->deserialize($this->framedBody(), $this->headers());
    }
}
