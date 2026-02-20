<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Contracts\MessageIdStamp;
use Freyr\MessageBroker\Contracts\MessageNameStamp;
use Freyr\MessageBroker\Serializer\Normalizer\CarbonImmutableNormalizer;
use Freyr\MessageBroker\Serializer\Normalizer\IdNormalizer;
use Freyr\MessageBroker\Serializer\WireFormatSerializer;
use Freyr\MessageBroker\Tests\Fixtures\TestOutboxEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Unit test for WireFormatSerializer.
 *
 * Tests that the serializer:
 * - Translates FQN to semantic name in type header on encode
 * - Adds X-Message-Class header on encode
 * - Throws when MessageNameStamp is missing
 * - Body contains only business data (no messageId)
 * - Restores FQN from X-Message-Class on decode
 * - Skips FQN replacement when type already contains backslash (retry path)
 * - Round-trips correctly (encode then decode)
 */
#[CoversClass(WireFormatSerializer::class)]
final class WireFormatSerializerTest extends TestCase
{
    private const TEST_MESSAGE_ID = '01234567-89ab-7def-8000-000000000001';

    private WireFormatSerializer $serializer;

    protected function setUp(): void
    {
        $reflectionExtractor = new ReflectionExtractor();
        $propertyTypeExtractor = new PropertyInfoExtractor(
            [$reflectionExtractor],
            [$reflectionExtractor],
            [],
            [$reflectionExtractor],
            [$reflectionExtractor]
        );

        $symfonySerializer = new Serializer(
            [
                new IdNormalizer(),
                new CarbonImmutableNormalizer(),
                new ArrayDenormalizer(),
                new ObjectNormalizer(null, null, null, $propertyTypeExtractor),
            ],
            [new JsonEncoder()]
        );

        $this->serializer = new WireFormatSerializer($symfonySerializer);
    }

    public function testEncodeProducesSemanticTypeHeader(): void
    {
        $encoded = $this->serializer->encode($this->createStampedEnvelope());

        /** @var array<string, string> $headers */
        $headers = $encoded['headers'];
        $this->assertSame('test.event.sent', $headers['type']);
    }

    public function testEncodeAddsMessageClassHeader(): void
    {
        $encoded = $this->serializer->encode($this->createStampedEnvelope());

        /** @var array<string, string> $headers */
        $headers = $encoded['headers'];
        $this->assertArrayHasKey('X-Message-Class', $headers);
        $this->assertSame(TestOutboxEvent::class, $headers['X-Message-Class']);
    }

    public function testEncodeThrowsWhenMessageNameStampMissing(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [
            new MessageIdStamp(Id::fromString(self::TEST_MESSAGE_ID)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must contain MessageNameStamp/');

        $this->serializer->encode($envelope);
    }

    public function testEncodeBodyContainsOnlyBusinessData(): void
    {
        $encoded = $this->serializer->encode($this->createStampedEnvelope());

        $this->assertIsString($encoded['body']);
        $body = json_decode($encoded['body'], true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('payload', $body);
        $this->assertArrayNotHasKey('messageId', $body, 'Body should not contain messageId');
    }

    public function testDecodeRestoresFqnFromMessageClassHeader(): void
    {
        $encoded = $this->serializer->encode($this->createStampedEnvelope());

        $decoded = $this->serializer->decode($encoded);

        $this->assertInstanceOf(TestOutboxEvent::class, $decoded->getMessage());
    }

    public function testDecodeSkipsReplacementWhenTypeContainsBackslash(): void
    {
        $encoded = $this->serializer->encode($this->createStampedEnvelope());

        /** @var array<string, string> $headers */
        $headers = $encoded['headers'];
        $headers['type'] = TestOutboxEvent::class;
        $encoded['headers'] = $headers;

        $decoded = $this->serializer->decode($encoded);

        $this->assertInstanceOf(TestOutboxEvent::class, $decoded->getMessage());
    }

    public function testRoundTripPreservesMessageData(): void
    {
        $id = Id::new();
        $timestamp = CarbonImmutable::now();
        $message = new TestOutboxEvent(eventId: $id, payload: 'Round-trip Test', occurredAt: $timestamp);
        $envelope = new Envelope($message, [
            new MessageIdStamp(Id::fromString(self::TEST_MESSAGE_ID)),
            new MessageNameStamp('test.event.sent'),
        ]);

        $encoded = $this->serializer->encode($envelope);
        $decoded = $this->serializer->decode($encoded);

        /** @var TestOutboxEvent $decodedMessage */
        $decodedMessage = $decoded->getMessage();
        $this->assertSame('Round-trip Test', $decodedMessage->payload);
        $this->assertSame((string) $id, (string) $decodedMessage->eventId);
    }

    private function createStampedEnvelope(): Envelope
    {
        return new Envelope(TestOutboxEvent::random(), [
            new MessageIdStamp(Id::fromString(self::TEST_MESSAGE_ID)),
            new MessageNameStamp('test.event.sent'),
        ]);
    }
}
