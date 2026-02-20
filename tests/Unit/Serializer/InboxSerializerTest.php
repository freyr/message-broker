<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Contracts\MessageNameStamp;
use Freyr\MessageBroker\Serializer\InboxSerializer;
use Freyr\MessageBroker\Serializer\Normalizer\CarbonImmutableNormalizer;
use Freyr\MessageBroker\Serializer\Normalizer\IdNormalizer;
use Freyr\MessageBroker\Tests\Fixtures\TestInboxEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Unit test for InboxSerializer.
 *
 * Tests that the serializer:
 * - Translates semantic type header to FQN via message_types mapping on decode
 * - Throws on missing type header, non-array headers, unknown type, FQN in type
 * - Adds MessageNameStamp with semantic name on decode
 * - Preserves existing MessageNameStamp on decode (retry path)
 * - Uses MessageNameStamp to set semantic type header on encode
 * - Preserves FQN in type header on encode without MessageNameStamp
 * - Round-trips decode then encode preserving semantic name
 */
#[CoversClass(InboxSerializer::class)]
final class InboxSerializerTest extends TestCase
{
    private InboxSerializer $serializer;

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

        $this->serializer = new InboxSerializer($symfonySerializer, [
            'test.inbox.received' => TestInboxEvent::class,
        ]);
    }

    public function testDecodeTranslatesSemanticNameToFqn(): void
    {
        $encoded = $this->createEncodedMessage('test.inbox.received');

        $decoded = $this->serializer->decode($encoded);

        $this->assertInstanceOf(TestInboxEvent::class, $decoded->getMessage());
    }

    public function testDecodeThrowsOnMissingTypeHeader(): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessageMatches('/type/');

        $this->serializer->decode([
            'body' => '{}',
            'headers' => [],
        ]);
    }

    public function testDecodeThrowsOnNonArrayHeaders(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode([
            'body' => '{}',
            'headers' => 'not-an-array',
        ]);
    }

    public function testDecodeThrowsOnUnknownMessageType(): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessageMatches('/Unknown message type.*unknown\.event/');

        $this->serializer->decode([
            'body' => '{}',
            'headers' => [
                'type' => 'unknown.event',
            ],
        ]);
    }

    public function testDecodeThrowsWhenFqnInTypeHeader(): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessageMatches('/Unknown message type/');

        $this->serializer->decode([
            'body' => '{}',
            'headers' => [
                'type' => TestInboxEvent::class,
            ],
        ]);
    }

    public function testDecodeAddsMessageNameStamp(): void
    {
        $encoded = $this->createEncodedMessage('test.inbox.received');

        $decoded = $this->serializer->decode($encoded);

        $stamp = $decoded->last(MessageNameStamp::class);
        $this->assertNotNull($stamp, 'MessageNameStamp should be added on decode');
        $this->assertSame('test.inbox.received', $stamp->messageName);
    }

    public function testDecodePreservesExistingMessageNameStamp(): void
    {
        $encoded = $this->createEncodedMessage('test.inbox.received');

        // Simulate retry path: MessageNameStamp already in X-Message-Stamp-* headers
        $encoded['headers']['X-Message-Stamp-'.MessageNameStamp::class] = json_encode([
            [
                'messageName' => 'test.inbox.received',
            ],
        ]);

        $decoded = $this->serializer->decode($encoded);

        $stamps = $decoded->all(MessageNameStamp::class);
        $this->assertCount(1, $stamps, 'Should not add duplicate MessageNameStamp');
    }

    public function testEncodeUsesMessageNameStampForSemanticType(): void
    {
        $message = TestInboxEvent::random();
        $envelope = new Envelope($message, [new MessageNameStamp('test.inbox.received')]);

        $encoded = $this->serializer->encode($envelope);

        /** @var array<string, string> $headers */
        $headers = $encoded['headers'];
        $this->assertSame('test.inbox.received', $headers['type']);
    }

    public function testEncodePreservesFqnWithoutMessageNameStamp(): void
    {
        $message = TestInboxEvent::random();
        $envelope = new Envelope($message);

        $encoded = $this->serializer->encode($envelope);

        /** @var array<string, string> $headers */
        $headers = $encoded['headers'];
        $this->assertSame(TestInboxEvent::class, $headers['type']);
    }

    public function testRoundTripDecodeEncodePreservesSemanticName(): void
    {
        $id = Id::new();
        $timestamp = CarbonImmutable::now();
        $body = json_encode([
            'eventId' => (string) $id,
            'payload' => 'Round-trip',
            'occurredAt' => $timestamp->toIso8601String(),
        ]);

        $decoded = $this->serializer->decode([
            'body' => $body,
            'headers' => [
                'type' => 'test.inbox.received',
            ],
        ]);

        $encoded = $this->serializer->encode($decoded);

        /** @var array<string, string> $headers */
        $headers = $encoded['headers'];
        $this->assertSame('test.inbox.received', $headers['type'], 'Semantic name should survive round-trip');
    }

    /**
     * @return array{body: string, headers: array<string, string>}
     */
    private function createEncodedMessage(string $type): array
    {
        $id = Id::new();
        $timestamp = CarbonImmutable::now();

        /** @var non-empty-string $body */
        $body = json_encode([
            'eventId' => (string) $id,
            'payload' => 'Test',
            'occurredAt' => $timestamp->toIso8601String(),
        ]);

        return [
            'body' => $body,
            'headers' => [
                'type' => $type,
            ],
        ];
    }
}
