<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializerInterface;

/**
 * Inbox Serializer (for AMQP Consumption).
 *
 * Only customizes decode() to translate semantic message names to PHP FQN.
 * encode() uses default behavior - important for retry/failed scenarios where
 * the message is already a consumer class without #[MessageName].
 *
 * Uses Symfony's native @serializer service with all registered normalizers.
 *
 * Usage: Configure on AMQP consumption transports (fsm.test, fsm.custom, etc.)
 */
final class InboxSerializer extends Serializer
{
    /**
     * @param array<string, class-string> $messageTypes Mapping: message_name => PHP class (for decode)
     * @param SymfonySerializerInterface $serializer Symfony's native @serializer service
     */
    public function __construct(
        private readonly array $messageTypes = [],
        SymfonySerializerInterface $serializer,
    ) {
        parent::__construct($serializer);
    }

    /**
     * Decode: Translate semantic name to consumer class FQN.
     *
     * Reads 'type' header (semantic name like 'fsm.test.message'),
     * looks up consumer class FQN in message_types mapping,
     * then delegates to native Symfony serializer.
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

        // Translate semantic name to consumer class FQN
        $fqn = $this->messageTypes[$messageName] ?? null;

        if ($fqn === null) {
            throw new MessageDecodingFailedException(sprintf(
                'Unknown message type "%s". Configure it in message_broker.inbox.message_types',
                $messageName
            ));
        }

        // Replace type header with consumer class FQN
        $headers['type'] = $fqn;
        $encodedEnvelope['headers'] = $headers;

        // Let native Symfony Serializer handle everything else:
        // - Stamp deserialization (MessageIdStamp, etc. from X-Message-Stamp-* headers)
        // - Body deserialization into consumer class
        // - Envelope creation
        return parent::decode($encodedEnvelope);
    }
}
