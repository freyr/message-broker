<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Freyr\MessageBroker\Serializer\JsonWireFormat;
use JsonException;
use PHPUnit\Framework\TestCase;

final class JsonWireFormatTest extends TestCase
{
    public function testEncodesPayloadOnlyAsJson(): void
    {
        $format = new JsonWireFormat();

        $body = $format->encode('order.placed', [
            'order_id' => 'o-1',
            'total_cents' => 4999,
        ]);

        self::assertSame('application/json', $format->contentType());
        self::assertSame([
            'order_id' => 'o-1',
            'total_cents' => 4999,
        ], json_decode($body, true), 'body is the payload only — no metadata envelope');
    }

    public function testUnencodablePayloadThrows(): void
    {
        $this->expectException(JsonException::class);

        new JsonWireFormat()
            ->encode('broken.message', [
                'value' => NAN,
            ]);
    }
}
