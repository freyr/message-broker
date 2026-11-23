<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\DeadLetter;

use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterBuilder;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The build of a DeadLetter from a delivery: the canonical document of a
 * deserialized message (replayable), the raw bytes of a message that did not
 * deserialize (not replayable), and the identity of a raw dead letter.
 */
final class DeadLetterBuilderTest extends TestCase
{
    private const string SOURCE = 'orders';

    private const int NOW = 1_795_219_200_123;

    private DeadLetterBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DeadLetterBuilder();
        Clock::set(new MockClock(EpochMillis::toDateTime(self::NOW)));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    /** @param array<string, mixed> $headers */
    private static function delivery(
        array $headers = [],
        string $body = '{"order_id":"o-1"}',
        int $attempt = 3,
        ?string $fallbackMessageId = null,
    ): Delivery {
        return new Delivery($body, $headers, $attempt, self::SOURCE, $fallbackMessageId, 7);
    }

    private static function incoming(string $messageId = 'm-9', string $note = 'o-1'): IncomingMessage
    {
        return new IncomingMessage($messageId, 'order.placed', 1_765_476_000_123, [
            'order_id' => $note,
        ], [
            'x-custom' => 'from-incoming',
        ]);
    }

    private static function assertGeneratedUnknownId(string $messageId): void
    {
        self::assertStringStartsWith('unknown-', $messageId);
        $uuid = substr($messageId, strlen('unknown-'));
        self::assertTrue(Uuid::isValid($uuid), "not a UUID: {$uuid}");
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($uuid));
        self::assertSame(Uuid::fromString($uuid)->toRfc4122(), $uuid, 'the canonical lower-case form');
    }

    private static function assertNewId(DeadLetter $deadLetter): void
    {
        self::assertTrue(Uuid::isValid($deadLetter->id));
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($deadLetter->id));
    }

    // canonical form, from a throwable

    public function testTheCanonicalFormOfAFailureHoldsTheDocumentAndIsReplayable(): void
    {
        $cause = new RuntimeException('root cause');
        $error = new RuntimeException('final failure', previous: $cause);

        $letter = $this->builder->canonical(self::delivery(), self::incoming(), $error);

        self::assertNewId($letter);
        self::assertSame(self::SOURCE, $letter->source);
        self::assertSame('m-9', $letter->messageId);
        self::assertSame('order.placed', $letter->messageName);
        self::assertSame(
            '{"metadata":{"message_name":"order.placed","message_id":"m-9","created_at":1765476000123},"payload":{"order_id":"o-1"}}',
            $letter->body,
        );
        self::assertSame([
            'x-custom' => 'from-incoming',
        ], $letter->headers, 'the headers of the deserialized message');
        self::assertSame(RuntimeException::class, $letter->errorClass);
        self::assertSame('final failure <- root cause', $letter->errorMessage);
        self::assertSame($error->getTraceAsString(), $letter->errorTrace);
        self::assertSame(3, $letter->attempts, 'the attempt of the delivery, never the delivery count');
        self::assertSame(self::NOW, $letter->failedAt);
        self::assertNull($letter->replayedAt);
        self::assertTrue($letter->replayable);
    }

    public function testTheCanonicalDocumentSubstitutesInvalidUtf8InsteadOfFailing(): void
    {
        $letter = $this->builder->canonical(
            self::delivery(),
            self::incoming(note: "caf\xE9"),
            new RuntimeException('final failure'),
        );

        $document = json_decode($letter->body, true);
        self::assertSame("caf\u{FFFD}", $document['payload']['order_id']);
    }

    // raw form, from a throwable

    public function testTheRawFormOfAFailureKeepsTheBytesTheEnvelopeIdentityAndIsNotReplayable(): void
    {
        $headers = MetadataHeader::envelope('m-bad', 'order.placed', 1_765_476_000_123) + [
            'x-custom' => 'h',
        ];
        $error = new RuntimeException('bad bytes');

        $letter = $this->builder->raw(self::delivery($headers, body: "{{{not json\xFF"), $error);

        self::assertNewId($letter);
        self::assertSame(self::SOURCE, $letter->source);
        self::assertSame('m-bad', $letter->messageId);
        self::assertSame('order.placed', $letter->messageName);
        self::assertSame("{{{not json\xFF", $letter->body, 'the exact bytes');
        self::assertSame($headers, $letter->headers, 'the headers of the delivery');
        self::assertSame(RuntimeException::class, $letter->errorClass);
        self::assertSame('bad bytes', $letter->errorMessage);
        self::assertSame($error->getTraceAsString(), $letter->errorTrace);
        self::assertSame(3, $letter->attempts);
        self::assertSame(self::NOW, $letter->failedAt);
        self::assertFalse($letter->replayable, 'raw wire bytes are not replayable');
    }

    public function testARawDeadLetterWithoutEnvelopeFallsBackToTheTransportMessageId(): void
    {
        $letter = $this->builder->raw(
            self::delivery(fallbackMessageId: 'from-property'),
            new RuntimeException('bad'),
        );

        self::assertSame('from-property', $letter->messageId);
        self::assertSame('unknown', $letter->messageName);
    }

    public function testARawDeadLetterWithAPartialEnvelopeFallsBackToTheTransportMessageId(): void
    {
        $letter = $this->builder->raw(
            self::delivery([
                MetadataHeader::MESSAGE_ID => 'm-partial',
            ], fallbackMessageId: 'from-property'),
            new RuntimeException('bad'),
        );

        self::assertSame('from-property', $letter->messageId);
        self::assertSame('unknown', $letter->messageName);
    }

    public function testARawDeadLetterWithoutAnyIdGetsAGeneratedUnknownId(): void
    {
        $letter = $this->builder->raw(self::delivery(), new RuntimeException('bad'));

        self::assertGeneratedUnknownId($letter->messageId);
        self::assertSame('unknown', $letter->messageName);
    }

    public function testTwoRawDeadLettersWithoutAnyIdGetDifferentIds(): void
    {
        $first = $this->builder->raw(self::delivery(), new RuntimeException('bad'));
        $second = $this->builder->raw(self::delivery(), new RuntimeException('bad'));

        self::assertGeneratedUnknownId($first->messageId);
        self::assertGeneratedUnknownId($second->messageId);
        self::assertNotSame($first->messageId, $second->messageId);
        self::assertNotSame($first->id, $second->id);
    }

    // the forms with explicit error fields (no throwable)

    public function testTheCanonicalFormWithExplicitErrorFieldsHasAnEmptyTrace(): void
    {
        $letter = $this->builder->canonicalWithError(
            delivery: self::delivery(),
            incoming: self::incoming(),
            source: 'work_q',
            errorClass: 'App\\Moved',
            errorMessage: 'moved by the broker',
            failedAt: 1_700_000_000_456,
            replayable: true,
        );

        self::assertNewId($letter);
        self::assertSame('work_q', $letter->source);
        self::assertSame('m-9', $letter->messageId);
        self::assertSame('order.placed', $letter->messageName);
        self::assertSame(
            '{"metadata":{"message_name":"order.placed","message_id":"m-9","created_at":1765476000123},"payload":{"order_id":"o-1"}}',
            $letter->body,
        );
        self::assertSame([
            'x-custom' => 'from-incoming',
        ], $letter->headers);
        self::assertSame('App\\Moved', $letter->errorClass);
        self::assertSame('moved by the broker', $letter->errorMessage);
        self::assertSame('', $letter->errorTrace);
        self::assertSame(3, $letter->attempts);
        self::assertSame(1_700_000_000_456, $letter->failedAt);
        self::assertNull($letter->replayedAt);
        self::assertTrue($letter->replayable);
    }

    public function testTheCanonicalFormWithExplicitErrorFieldsCanBeNotReplayable(): void
    {
        $letter = $this->builder->canonicalWithError(
            delivery: self::delivery(),
            incoming: self::incoming(),
            source: 'work_q',
            errorClass: 'App\\Moved',
            errorMessage: 'moved',
            failedAt: 1,
            replayable: false,
        );

        self::assertFalse($letter->replayable);
    }

    public function testTheRawFormWithExplicitErrorFieldsKeepsTheBytesAndIsNotReplayable(): void
    {
        $headers = MetadataHeader::envelope('m-bad', 'order.placed', 1_765_476_000_123) + [
            'x-death' => [[
                'queue' => 'work_q',
            ]],
        ];

        $letter = $this->builder->rawWithError(
            delivery: self::delivery($headers, body: "\x00\xFFraw"),
            source: 'work_q',
            errorClass: 'App\\Moved',
            errorMessage: 'moved by the broker',
            failedAt: 1_700_000_000_456,
        );

        self::assertNewId($letter);
        self::assertSame('work_q', $letter->source);
        self::assertSame('m-bad', $letter->messageId);
        self::assertSame('order.placed', $letter->messageName);
        self::assertSame("\x00\xFFraw", $letter->body);
        self::assertSame($headers, $letter->headers);
        self::assertSame('App\\Moved', $letter->errorClass);
        self::assertSame('moved by the broker', $letter->errorMessage);
        self::assertSame('', $letter->errorTrace);
        self::assertSame(3, $letter->attempts);
        self::assertSame(1_700_000_000_456, $letter->failedAt);
        self::assertFalse($letter->replayable);
    }

    public function testTheRawFormWithExplicitErrorFieldsUsesTheRawIdentity(): void
    {
        $withFallback = $this->builder->rawWithError(self::delivery(fallbackMessageId: 'prop-id'), 'q', 'C', 'm', 1);
        $withNoId = $this->builder->rawWithError(self::delivery(), 'q', 'C', 'm', 1);

        self::assertSame('prop-id', $withFallback->messageId);
        self::assertSame('unknown', $withFallback->messageName);
        self::assertGeneratedUnknownId($withNoId->messageId);
        self::assertSame('unknown', $withNoId->messageName);
    }
}
