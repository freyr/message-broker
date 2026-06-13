<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Freyr\MessageBroker\Serializer\JsonSerializer;
use Freyr\MessageBroker\Serializer\WireMessage;
use PHPUnit\Framework\TestCase;

final class JsonSerializerTest extends TestCase
{
    public function testSerializesDocumentToJsonWireMessage(): void
    {
        $wire = [
            'metadata' => [
                'message_name' => 'order.placed',
                'message_id' => '0190d2f3-4a5b-7c8d-9e0f-1a2b3c4d5e6f',
                'created_at' => 1_749_722_400_123,
            ],
            'payload' => [
                'order_id' => 'o-1',
            ],
        ];

        $message = new JsonSerializer()
            ->serialize($wire);

        self::assertInstanceOf(WireMessage::class, $message);
        self::assertSame($wire, json_decode($message->bytes, true));
        self::assertSame('application/json', $message->contentType);
        self::assertSame([], $message->headers, 'JSON carries the whole document in the body — no headers');
    }
}
