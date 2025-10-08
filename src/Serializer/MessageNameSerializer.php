<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Outbox\MessageName;
use ReflectionClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;

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
    ) {
        parent::__construct();
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
        $encoded['headers']['type'] = $messageName;

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
        if (empty($encodedEnvelope['headers']['type'])) {
            throw new MessageDecodingFailedException('Encoded envelope does not have a "type" header.');
        }

        $messageName = $encodedEnvelope['headers']['type'];

        // Translate semantic name to FQN
        $fqn = $this->messageTypes[$messageName] ?? null;

        if ($fqn === null) {
            throw new MessageDecodingFailedException(
                sprintf('Unknown message type "%s". Configure it in message_broker.inbox.message_types', $messageName)
            );
        }

        // Replace type header with FQN
        $encodedEnvelope['headers']['type'] = $fqn;

        // Let native Symfony Serializer handle everything else:
        // - Stamp deserialization (MessageIdStamp, etc. from X-Message-Stamp-* headers)
        // - Body deserialization using Symfony Serializer
        // - Envelope creation
        return parent::decode($encodedEnvelope);
    }

    private function extractMessageName(object $message): string
    {
        $reflection = new ReflectionClass($message);
        $attributes = $reflection->getAttributes(MessageName::class);

        if (empty($attributes)) {
            throw new \RuntimeException(
                sprintf('Message %s must have #[MessageName] attribute', $message::class)
            );
        }

        /** @var MessageName $messageNameAttr */
        $messageNameAttr = $attributes[0]->newInstance();

        return $messageNameAttr->name;
    }
}
