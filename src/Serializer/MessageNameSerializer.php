<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Serializer\Normalizer\CarbonImmutableNormalizer;
use Freyr\MessageBroker\Serializer\Normalizer\IdNormalizer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer as SymfonySerializer;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializerInterface;

/**
 * Message Name Serializer.
 *
 * Unified serializer for both inbox and outbox using the "Fake FQN" pattern:
 * - encode(): Sets 'type' header to semantic message name (not FQN) for external systems
 * - decode(): Translates semantic name to PHP FQN, then delegates to native Symfony Serializer
 *
 * This is the ONLY deviation from native Symfony behavior - everything else (stamps, body serialization) is standard.
 */
final class MessageNameSerializer extends Serializer
{
    /**
     * @param array<string, class-string> $messageTypes Mapping: message_name => PHP class (for decode)
     */
    public function __construct(
        private readonly array $messageTypes = [],
        ?SymfonySerializerInterface $serializer = null,
    ) {
        parent::__construct($serializer ?? self::createDefaultSerializer());
    }

    private static function createDefaultSerializer(): SymfonySerializerInterface
    {
        $extractor = new PropertyInfoExtractor(typeExtractors: [new ReflectionExtractor()]);

        $normalizers = [
            new IdNormalizer(),
            new CarbonImmutableNormalizer(),
            new DateTimeNormalizer(),
            new ArrayDenormalizer(),
            new ObjectNormalizer(propertyTypeExtractor: $extractor),
        ];

        $encoders = [new JsonEncoder()];

        return new SymfonySerializer($normalizers, $encoders);
    }

    /**
     * Encode (Outbox): Extract semantic name from #[MessageName] attribute and set as 'type' header.
     *
     * @return array<string, mixed>
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        // Extract semantic message name from attribute
        $messageName = $this->extractMessageName($message);

        // Let parent serialize (body + stamps)
        $encoded = parent::encode($envelope);

        // Override 'type' header with semantic name instead of FQN
        $headers = $encoded['headers'] ?? [];
        assert(is_array($headers));
        $headers['type'] = $messageName;
        $encoded['headers'] = $headers;

        /** @var array<string, mixed> $encoded */
        // Stamps are automatically serialized to X-Message-Stamp-* headers by parent!
        return $encoded;
    }

    /**
     * Decode (Inbox): Translate semantic name to FQN, then delegate to native serializer.
     *
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $headers = $encodedEnvelope['headers'] ?? [];
        assert(is_array($headers));

        if (empty($headers['type'])) {
            throw new MessageDecodingFailedException('Encoded envelope does not have a "type" header.');
        }

        $messageName = $headers['type'];
        assert(is_string($messageName));

        // Translate semantic name to FQN
        $fqn = $this->messageTypes[$messageName] ?? null;

        if ($fqn === null) {
            throw new MessageDecodingFailedException(sprintf(
                'Unknown message type "%s". Configure it in message_broker.inbox.message_types',
                $messageName
            ));
        }

        // Replace type header with FQN
        $headers['type'] = $fqn;
        $encodedEnvelope['headers'] = $headers;

        // Let native Symfony Serializer handle everything else:
        // - Stamp deserialization (MessageIdStamp, etc. from X-Message-Stamp-* headers)
        // - Body deserialization using Symfony Serializer
        // - Envelope creation
        return parent::decode($encodedEnvelope);
    }

    private function extractMessageName(object $message): string
    {
        $reflection = new \ReflectionClass($message);
        $attributes = $reflection->getAttributes(MessageName::class);

        if (empty($attributes)) {
            throw new \RuntimeException(sprintf('Message %s must have #[MessageName] attribute', $message::class));
        }

        /** @var MessageName $messageNameAttr */
        $messageNameAttr = $attributes[0]->newInstance();

        return $messageNameAttr->name;
    }
}
