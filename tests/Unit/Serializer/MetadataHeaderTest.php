<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use PHPUnit\Framework\TestCase;

final class MetadataHeaderTest extends TestCase
{
    public function testExplodesMetadataIntoIndividualHeaders(): void
    {
        $headers = MetadataHeader::explode([
            'message_name' => 'order.placed',
            'message_id' => 'm-1',
            'created_at' => 1_749_722_400_123,
        ]);

        self::assertSame([
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::MESSAGE_ID => 'm-1',
            MetadataHeader::CREATED_AT => 1_749_722_400_123,
        ], $headers);
    }

    public function testExplodeMapsFutureFieldsByConvention(): void
    {
        // Underscores become dashes; future fields auto-map with no relay change.
        self::assertSame([
            'x-trace-id' => 't-9',
        ], MetadataHeader::explode([
            'trace_id' => 't-9',
        ]));
    }

    public function testExplodeAndParseRoundTripTheEnvelope(): void
    {
        $meta = MetadataHeader::parse(MetadataHeader::explode([
            'message_name' => 'order.placed',
            'message_id' => 'm-1',
            'created_at' => 1_749_722_400_123,
        ]));

        self::assertSame('m-1', $meta['message_id']);
        self::assertSame('order.placed', $meta['message_name']);
        self::assertSame(1_749_722_400_123, $meta['created_at']);
    }

    public function testParseReadsIndividualHeadersAndIgnoresExtras(): void
    {
        $meta = MetadataHeader::parse([
            MetadataHeader::MESSAGE_ID => 'm-1',
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::CREATED_AT => 1_749_722_400_123,
            'x-custom' => 'ignored',
        ]);

        self::assertSame('m-1', $meta['message_id']);
        self::assertSame('order.placed', $meta['message_name']);
        self::assertSame(1_749_722_400_123, $meta['created_at']);
    }

    public function testMissingHeaderIsMalformed(): void
    {
        $this->expectException(MalformedMessage::class);

        MetadataHeader::parse([]);
    }

    public function testNonIntCreatedAtIsMalformed(): void
    {
        // Matches the shipped slice-2 deserializer: x-created-at must be an int.
        $this->expectException(MalformedMessage::class);

        MetadataHeader::parse([
            MetadataHeader::MESSAGE_ID => 'm-1',
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::CREATED_AT => 'not-an-int',
        ]);
    }
}
