<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Serializer\Normalizer\CarbonImmutableNormalizer;
use Freyr\MessageBroker\Serializer\Normalizer\IdNormalizer;
use Freyr\MessageBroker\Serializer\WireFormatSerializer;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Stamp\MessageNameStamp;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
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
 * - Preserves native X-Message-Stamp-* headers (no stripping)
 * - Throws when MessageNameStamp is missing
 * - Restores FQN from X-Message-Class on decode
 * - Round-trips correctly (encode then decode)
 */
final class WireFormatSerializerTest extends TestCase
{
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
        $envelope = $this->createStampedEnvelope();

        $encoded = $this->serializer->encode($envelope);

        $this->assertSame('test.message.sent', $encoded['headers']['type']);
    }

    public function testEncodeAddsMessageClassHeader(): void
    {
        $envelope = $this->createStampedEnvelope();

        $encoded = $this->serializer->encode($envelope);

        $this->assertArrayHasKey('X-Message-Class', $encoded['headers']);
        $this->assertSame(TestMessage::class, $encoded['headers']['X-Message-Class']);
    }

    public function testEncodePreservesStampHeaders(): void
    {
        $envelope = $this->createStampedEnvelope();

        $encoded = $this->serializer->encode($envelope);

        $this->assertArrayHasKey(
            'X-Message-Stamp-' . MessageIdStamp::class,
            $encoded['headers'],
            'MessageIdStamp header should be preserved'
        );
        $this->assertArrayHasKey(
            'X-Message-Stamp-' . MessageNameStamp::class,
            $encoded['headers'],
            'MessageNameStamp header should be preserved'
        );
    }

    public function testEncodeThrowsWhenMessageNameStampMissing(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [
            new MessageIdStamp(Id::fromString('01234567-89ab-7def-8000-000000000001')),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must contain MessageNameStamp/');

        $this->serializer->encode($envelope);
    }

    public function testDecodeRestoresFqnFromMessageClassHeader(): void
    {
        $envelope = $this->createStampedEnvelope();
        $encoded = $this->serializer->encode($envelope);

        $decoded = $this->serializer->decode($encoded);

        $this->assertInstanceOf(TestMessage::class, $decoded->getMessage());
    }

    public function testDecodeRestoresMessageNameStamp(): void
    {
        $envelope = $this->createStampedEnvelope();
        $encoded = $this->serializer->encode($envelope);

        $decoded = $this->serializer->decode($encoded);

        $stamp = $decoded->last(MessageNameStamp::class);
        $this->assertNotNull($stamp, 'MessageNameStamp should be restored on decode');
        $this->assertSame('test.message.sent', $stamp->messageName);
    }

    public function testDecodeRestoresMessageIdStamp(): void
    {
        $envelope = $this->createStampedEnvelope();
        $encoded = $this->serializer->encode($envelope);

        $decoded = $this->serializer->decode($encoded);

        $stamp = $decoded->last(MessageIdStamp::class);
        $this->assertNotNull($stamp, 'MessageIdStamp should be restored on decode');
        $this->assertSame('01234567-89ab-7def-8000-000000000001', (string) $stamp->messageId);
    }

    public function testRoundTripPreservesMessageData(): void
    {
        $id = Id::new();
        $timestamp = CarbonImmutable::now();
        $message = new TestMessage(id: $id, name: 'Round-trip Test', timestamp: $timestamp);
        $envelope = new Envelope($message, [
            new MessageIdStamp(Id::fromString('01234567-89ab-7def-8000-000000000001')),
            new MessageNameStamp('test.message.sent'),
        ]);

        $encoded = $this->serializer->encode($envelope);
        $decoded = $this->serializer->decode($encoded);

        /** @var TestMessage $decodedMessage */
        $decodedMessage = $decoded->getMessage();
        $this->assertSame('Round-trip Test', $decodedMessage->name);
        $this->assertSame((string) $id, (string) $decodedMessage->id);
    }

    public function testEncodeBodyContainsOnlyBusinessData(): void
    {
        $envelope = $this->createStampedEnvelope();

        $encoded = $this->serializer->encode($envelope);

        $body = json_decode($encoded['body'], true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('name', $body);
        $this->assertArrayNotHasKey('messageId', $body, 'Body should not contain messageId');
    }

    private function createStampedEnvelope(): Envelope
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());

        return new Envelope($message, [
            new MessageIdStamp(Id::fromString('01234567-89ab-7def-8000-000000000001')),
            new MessageNameStamp('test.message.sent'),
        ]);
    }
}
