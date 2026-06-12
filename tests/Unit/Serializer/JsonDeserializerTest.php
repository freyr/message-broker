<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonDeserializerTest extends TestCase
{
    private JsonDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->deserializer = new JsonDeserializer();
    }

    public function testDeserializesValidTwoSectionDocument(): void
    {
        $bytes = (string) json_encode([
            'metadata' => [
                'message_id' => '0190a8b0-0000-7000-8000-000000000001',
                'message_name' => 'order.placed',
                'created_at' => 1765476000123,
            ],
            'payload' => [
                'order_id' => 'o-1',
            ],
        ]);

        $incoming = $this->deserializer->deserialize($bytes, [
            'x-custom' => 'h-1',
        ]);

        self::assertSame('0190a8b0-0000-7000-8000-000000000001', $incoming->messageId);
        self::assertSame('order.placed', $incoming->messageName);
        self::assertSame(1765476000123, $incoming->createdAt);
        self::assertSame([
            'order_id' => 'o-1',
        ], $incoming->payload);
        self::assertSame([
            'x-custom' => 'h-1',
        ], $incoming->headers);
    }

    /** @param non-empty-string $bytes */
    #[DataProvider('malformedDocuments')]
    public function testRejectsMalformedDocuments(string $bytes): void
    {
        $this->expectException(MalformedMessage::class);

        $this->deserializer->deserialize($bytes);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedDocuments(): iterable
    {
        yield 'not json at all' => ['{{{'];
        yield 'json but not an object' => ['"just a string"'];
        yield 'missing metadata section' => ['{"payload":{}}'];
        yield 'missing payload section' => ['{"metadata":{"message_id":"x","message_name":"y","created_at":1}}'];
        yield 'metadata not an object' => ['{"metadata":"nope","payload":{}}'];
        yield 'payload not an object' => [
            '{"metadata":{"message_id":"x","message_name":"y","created_at":1},"payload":"nope"}',
        ];
        yield 'message_id not a string' => [
            '{"metadata":{"message_id":1,"message_name":"y","created_at":1},"payload":{}}',
        ];
        yield 'message_name missing' => ['{"metadata":{"message_id":"x","created_at":1},"payload":{}}'];
        yield 'created_at not an integer' => [
            '{"metadata":{"message_id":"x","message_name":"y","created_at":"2026-01-01"},"payload":{}}',
        ];
    }
}
