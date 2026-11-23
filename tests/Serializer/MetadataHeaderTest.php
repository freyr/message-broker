<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Serializer;

use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetadataHeaderTest extends TestCase
{
    public function testEnvelopeBuildsIndividualHeaders(): void
    {
        self::assertSame([
            MetadataHeader::MESSAGE_ID => 'm-1',
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::CREATED_AT => 1_749_722_400_123,
        ], MetadataHeader::envelope('m-1', 'order.placed', 1_749_722_400_123));
    }

    public function testEnvelopeAndParseRoundTrip(): void
    {
        $meta = MetadataHeader::parse(MetadataHeader::envelope('m-1', 'order.placed', 1_749_722_400_123));

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

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incompleteEnvelopes(): iterable
    {
        yield 'message id is not a string' => [[
            MetadataHeader::MESSAGE_ID => 5,
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::CREATED_AT => 1_749_722_400_123,
        ]];
        yield 'message id missing' => [[
            MetadataHeader::MESSAGE_NAME => 'order.placed',
            MetadataHeader::CREATED_AT => 1_749_722_400_123,
        ]];
        yield 'message name is not a string' => [[
            MetadataHeader::MESSAGE_ID => 'm-1',
            MetadataHeader::MESSAGE_NAME => ['order.placed'],
            MetadataHeader::CREATED_AT => 1_749_722_400_123,
        ]];
        yield 'message name missing' => [[
            MetadataHeader::MESSAGE_ID => 'm-1',
            MetadataHeader::CREATED_AT => 1_749_722_400_123,
        ]];
        yield 'created at missing' => [[
            MetadataHeader::MESSAGE_ID => 'm-1',
            MetadataHeader::MESSAGE_NAME => 'order.placed',
        ]];
    }

    /** @param array<string, mixed> $headers */
    #[DataProvider('incompleteEnvelopes')]
    public function testEachEnvelopeHeaderIsRequiredWithItsType(array $headers): void
    {
        $this->expectException(MalformedMessage::class);

        MetadataHeader::parse($headers);
    }

    public function testMalformedMessageNamesTheThreeRequiredHeaders(): void
    {
        $this->expectException(MalformedMessage::class);
        $this->expectExceptionMessage(
            'Delivery requires x-message-id (string), x-message-name (string) and x-created-at (epoch ms int) headers',
        );

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
