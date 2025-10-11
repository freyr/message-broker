<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Outbox\MessageName;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializerInterface;

/**
 * Outbox Serializer (for AMQP Publishing).
 *
 * Only customizes encode() to replace producer class FQN with semantic message name.
 * decode() uses default behavior (should never be called - messages are published, not consumed).
 *
 * Uses Symfony's native @serializer service with all registered normalizers.
 *
 * Usage: Configure on AMQP publishing transports that receive from OutboxToAmqpBridge.
 *
 * Flow:
 * 1. OutboxToAmqpBridge dispatches producer class (with #[MessageName])
 * 2. OutboxSerializer.encode() extracts semantic name from #[MessageName]
 * 3. Sets 'type' header to semantic name (e.g., 'fsm.test.message')
 * 4. Published to AMQP with language-agnostic semantic name
 */
final class OutboxSerializer extends Serializer
{
    /**
     * @param SymfonySerializerInterface $serializer Symfony's native @serializer service
     */
    public function __construct(SymfonySerializerInterface $serializer)
    {
        parent::__construct($serializer);
    }

    /**
     * Encode: Extract semantic name from #[MessageName] and set as 'type' header.
     *
     * Producer classes MUST have #[MessageName] attribute.
     * Semantic name replaces PHP FQN for language-agnostic messaging.
     *
     * @return array<string, mixed>
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        // Extract semantic message name from #[MessageName] attribute
        $messageName = $this->extractMessageName($message);

        // Let parent serialize (body + stamps)
        $encoded = parent::encode($envelope);

        // Override 'type' header with semantic name instead of PHP FQN
        $headers = $encoded['headers'] ?? [];
        assert(is_array($headers));
        $headers['type'] = $messageName;
        $encoded['headers'] = $headers;

        /** @var array<string, mixed> $encoded */
        // Stamps are automatically serialized to X-Message-Stamp-* headers by parent!
        return $encoded;
    }

    private function extractMessageName(object $message): string
    {
        $reflection = new \ReflectionClass($message);
        $attributes = $reflection->getAttributes(MessageName::class);

        if (empty($attributes)) {
            throw new \RuntimeException(sprintf(
                'Producer message %s must have #[MessageName] attribute',
                $message::class
            ));
        }

        /** @var MessageName $messageNameAttr */
        $messageNameAttr = $attributes[0]->newInstance();

        return $messageNameAttr->name;
    }
}
