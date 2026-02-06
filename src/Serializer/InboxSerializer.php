<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Inbox Serializer (for AMQP Consumption).
 *
 * Handles semantic-to-FQN translation for consumed messages:
 * - decode(): Semantic name (e.g., 'order.placed') → FQN (e.g., 'App\Message\OrderPlaced')
 * - encode(): FQN → Semantic name (for retry/failed scenarios)
 *
 * Uses Symfony's native @serializer service with all registered normalizers.
 *
 * Usage: Configure on AMQP consumption transports.
 */
final class InboxSerializer extends Serializer
{
    /**
     * @param SerializerInterface $serializer Symfony's native @serializer service
     * @param array<string, class-string> $messageTypes Mapping: semantic_name => FQN
     */
    public function __construct(
        SerializerInterface $serializer,
        private readonly array $messageTypes = [],
    ) {
        parent::__construct($serializer);
    }

    /**
     * Decode: Translate semantic name to FQN.
     *
     * Flow:
     * 1. Read semantic name from 'type' header (e.g., 'order.placed')
     * 2. Look up FQN in messageTypes mapping
     * 3. Replace 'type' header with FQN for parent decoder
     * 4. Store semantic name in MessageNameStamp for encode()
     *
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $headers = $encodedEnvelope['headers'] ?? [];

        if (!is_array($headers)) {
            throw new MessageDecodingFailedException('Encoded envelope headers must be an array.');
        }

        $semanticName = $headers['type'] ?? null;

        if (!is_string($semanticName) || $semanticName === '') {
            throw new MessageDecodingFailedException('Encoded envelope does not have a "type" header.');
        }

        // Look up FQN from semantic name
        $fqn = $this->messageTypes[$semanticName] ?? null;

        if ($fqn === null) {
            throw new MessageDecodingFailedException(sprintf(
                'Unknown message type "%s". Configure it in message_broker.inbox.message_types',
                $semanticName
            ));
        }

        // Replace 'type' header with FQN for parent decode
        $encodedEnvelope['headers']['type'] = $fqn;

        // Decode with FQN
        $envelope = parent::decode($encodedEnvelope);

        // Attach semantic name stamp (avoid duplicates on retry)
        $existingStamp = $envelope->last(MessageNameStamp::class);
        if (!$existingStamp instanceof MessageNameStamp) {
            $envelope = $envelope->with(new MessageNameStamp($semanticName));
        }

        return $envelope;
    }

    /**
     * Encode: Restore semantic name from MessageNameStamp.
     *
     * Flow:
     * 1. Let parent encode (produces FQN in 'type' header)
     * 2. Check for MessageNameStamp (added during decode)
     * 3. Replace 'type' header with semantic name
     *
     * @return array<string, mixed>
     */
    public function encode(Envelope $envelope): array
    {
        // Parent encode produces FQN in 'type' header
        $encoded = parent::encode($envelope);

        // Retrieve semantic name from stamp
        $messageNameStamp = $envelope->last(MessageNameStamp::class);

        if ($messageNameStamp instanceof MessageNameStamp) {
            // Replace FQN with semantic name in 'type' header
            $headers = $encoded['headers'] ?? [];
            $headers['type'] = $messageNameStamp->messageName;
            $encoded['headers'] = $headers;
        }

        /** @var array<string, mixed> $encoded */
        return $encoded;
    }
}
