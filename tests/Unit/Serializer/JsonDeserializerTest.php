<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use PHPUnit\Framework\TestCase;

final class JsonDeserializerTest extends TestCase
{
    private JsonDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->deserializer = new JsonDeserializer();
    }

    public function testDeserializesPayloadBodyWithEnvelopeHeaders(): void
    {
        $bytes = (string) json_encode([
            'order_id' => 'o-1',
        ]);

        $incoming = $this->deserializer->deserialize($bytes, [
            MetadataHeader::MESSAGE_ID => '0190a8b0-0000-7000-8000-000000000001',
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::CREATED_AT => 1765476000123,
            'x-custom' => 'h-1',
        ]);

        self::assertSame('0190a8b0-0000-7000-8000-000000000001', $incoming->messageId);
        self::assertSame('order.placed', $incoming->messageName);
        self::assertSame(1765476000123, $incoming->createdAt);
        self::assertSame([
            'order_id' => 'o-1',
        ], $incoming->payload);
        self::assertSame('h-1', $incoming->headers['x-custom']);
    }

    public function testMissingEnvelopeHeaderThrowsMalformedMessage(): void
    {
        $bytes = (string) json_encode([
            'order_id' => 'o-1',
        ]);

        $this->expectException(MalformedMessage::class);

        $this->deserializer->deserialize($bytes, []);
    }

    public function testNonJsonBodyThrowsMalformedMessage(): void
    {
        $this->expectException(MalformedMessage::class);

        $this->deserializer->deserialize('{{{not json', [
            MetadataHeader::MESSAGE_ID => 'm-1',
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::CREATED_AT => 1765476000123,
        ]);
    }

    public function testBodyNotDecodingToObjectThrowsMalformedMessage(): void
    {
        $bytes = (string) json_encode('just a string');

        $this->expectException(MalformedMessage::class);

        $this->deserializer->deserialize($bytes, [
            MetadataHeader::MESSAGE_ID => 'm-1',
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::CREATED_AT => 1765476000123,
        ]);
    }
}
