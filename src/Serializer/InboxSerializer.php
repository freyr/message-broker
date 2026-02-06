<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Stamp\MessageIdStamp;
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
 * Also manages X-Message-Id header → MessageIdStamp translation,
 * ensuring the wire format never contains PHP class FQNs for stamps.
 *
 * Uses Symfony's native @serializer service with all registered normalizers.
 *
 * Usage: Configure on AMQP consumption transports.
 */
final class InboxSerializer extends Serializer
{
    private const string MESSAGE_ID_HEADER = 'X-Message-Id';

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
     * 1. Read semantic name from the 'type' header (e.g., 'order.placed')
     * 2. Look up FQN in messageTypes mapping
     * 3. Replace the 'type' header with FQN for parent decoder
     * 4. Store semantic name in MessageNameStamp for encode()
     * 5. Read X-Message-Id header and attach MessageIdStamp
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

        // Replace the 'type' header with FQN for parent decode
        $headers['type'] = $fqn;

        // Extract message ID from a semantic header
        $messageId = isset($headers[self::MESSAGE_ID_HEADER]) && is_string($headers[self::MESSAGE_ID_HEADER])
            ? $headers[self::MESSAGE_ID_HEADER]
            : null;

        // Strip auto-generated stamp header so the parent doesn't try to deserialize it
        unset($headers['X-Message-Stamp-'.MessageIdStamp::class]);

        // Write modified headers back
        $encodedEnvelope['headers'] = $headers;

        /** @var array{body: string, headers?: array<string, string>} $encodedEnvelope */
        $envelope = parent::decode($encodedEnvelope);

        // Attach semantic name stamp (avoid duplicates on retry)
        $existingStamp = $envelope->last(MessageNameStamp::class);
        if (!$existingStamp instanceof MessageNameStamp) {
            $envelope = $envelope->with(new MessageNameStamp($semanticName));
        }

        // Attach MessageIdStamp from X-Message-Id header
        if ($messageId !== null && !$envelope->last(MessageIdStamp::class) instanceof MessageIdStamp) {
            $envelope = $envelope->with(new MessageIdStamp($messageId));
        }

        return $envelope;
    }

    /**
     * Encode: Restore semantic name from MessageNameStamp.
     *
     * Flow:
     * 1. Let parent encode (produces FQN in 'type' header)
     * 2. Check for MessageNameStamp (added during decoding)
     * 3. Replace the 'type' header with a semantic name
     * 4. Replace auto-generated X-Message-Stamp-MessageIdStamp with X-Message-Id
     *
     * @return array<string, mixed>
     */
    public function encode(Envelope $envelope): array
    {
        // Parent encode produces FQN in the 'type' header
        $encoded = parent::encode($envelope);

        $headers = $encoded['headers'] ?? [];

        // Retrieve semantic name from a stamp
        $messageNameStamp = $envelope->last(MessageNameStamp::class);
        if ($messageNameStamp instanceof MessageNameStamp) {
            $headers['type'] = $messageNameStamp->messageName;
        }

        // Replace the auto-generated stamp header with semantic X-Message-Id
        $messageIdStamp = $envelope->last(MessageIdStamp::class);
        if ($messageIdStamp instanceof MessageIdStamp) {
            $headers[self::MESSAGE_ID_HEADER] = $messageIdStamp->messageId;
            unset($headers['X-Message-Stamp-'.MessageIdStamp::class]);
        }

        $encoded['headers'] = $headers;

        /** @var array<string, mixed> $encoded */
        return $encoded;
    }
}
