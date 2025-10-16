<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Inbox\MessageNameStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializerInterface;

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
     * @param array<string, class-string> $messageTypes Mapping: semantic_name => FQN
     * @param SymfonySerializerInterface $serializer Symfony's native @serializer service
     */
    public function __construct(
        private readonly array $messageTypes = [],
        SymfonySerializerInterface $serializer,
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
        assert(is_array($headers));

        $semanticName = $headers['type'] ?? null;

        if (empty($semanticName)) {
            throw new MessageDecodingFailedException('Encoded envelope does not have a "type" header.');
        }

        assert(is_string($semanticName));

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

        // Decode with FQN and attach semantic name stamp
        $envelope = parent::decode($encodedEnvelope);

        return $envelope->with(new MessageNameStamp($semanticName));
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
            assert(is_array($headers));
            $headers['type'] = $messageNameStamp->messageName;
            $encoded['headers'] = $headers;
        }

        /** @var array<string, mixed> $encoded */
        return $encoded;
    }
}
