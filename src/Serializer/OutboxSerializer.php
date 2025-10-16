<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Inbox\MessageNameStamp;
use Freyr\MessageBroker\Outbox\MessageName;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializerInterface;

/**
 * Outbox Serializer (for AMQP Publishing).
 *
 * Handles FQN-to-semantic translation for published messages:
 * - encode(): FQN (e.g., 'App\Event\OrderPlaced') → Semantic name (e.g., 'order.placed')
 * - decode(): Semantic name → FQN (for retry/failed scenarios)
 *
 * Uses Symfony's native @serializer service with all registered normalizers.
 *
 * Usage: Configure on AMQP publishing transports.
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
     * Encode: Extract semantic name from #[MessageName] attribute.
     *
     * Flow:
     * 1. Extract semantic name from message #[MessageName] attribute
     * 2. Add MessageNameStamp to envelope
     * 3. Let parent encode (produces FQN in 'type' header)
     * 4. Store FQN in 'X-Message-Class' header for decode()
     * 5. Replace 'type' header with semantic name
     *
     * @return array<string, mixed>
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        $fqn = $message::class;

        // Extract semantic name from #[MessageName] attribute
        $semanticName = $this->extractMessageName($message);

        // Add MessageNameStamp if not present
        if (!$envelope->last(MessageNameStamp::class)) {
            $envelope = $envelope->with(new MessageNameStamp($semanticName));
        }

        // Parent encode produces FQN in 'type' header
        $encoded = parent::encode($envelope);

        // Preserve FQN and replace 'type' with semantic name
        $headers = $encoded['headers'] ?? [];
        assert(is_array($headers));
        $headers['X-Message-Class'] = $fqn;         // Preserve FQN for decode()
        $headers['type'] = $semanticName;           // Replace with semantic name
        $encoded['headers'] = $headers;

        /** @var array<string, mixed> $encoded */
        return $encoded;
    }

    /**
     * Decode: Restore FQN from X-Message-Class header.
     *
     * Flow:
     * 1. Read semantic name from 'type' header
     * 2. Read FQN from 'X-Message-Class' header
     * 3. Replace 'type' header with FQN for parent decoder
     * 4. Store semantic name in MessageNameStamp for encode()
     *
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $headers = $encodedEnvelope['headers'] ?? [];
        assert(is_array($headers));

        $semanticName = $headers['type'] ?? null;
        $fqn = $headers['X-Message-Class'] ?? null;

        // Restore FQN if we have semantic name (identified by lack of backslash)
        if (is_string($semanticName) && is_string($fqn) && !str_contains($semanticName, '\\')) {
            // Replace 'type' header with FQN for parent decode
            $encodedEnvelope['headers']['type'] = $fqn;
        }

        // Decode with FQN
        $envelope = parent::decode($encodedEnvelope);

        // Attach semantic name stamp for future encode()
        if (is_string($semanticName) && !str_contains($semanticName, '\\')) {
            $envelope = $envelope->with(new MessageNameStamp($semanticName));
        }

        return $envelope;
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
