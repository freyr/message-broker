<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
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
 * Also manages MessageIdStamp ↔ X-Message-Id header translation,
 * ensuring the wire format never contains PHP class FQNs.
 *
 * Uses Symfony's native @serializer service with all registered normalizers.
 *
 * Usage: Configure on AMQP publishing transports.
 */
final class OutboxSerializer extends Serializer
{
    private const MESSAGE_ID_HEADER = 'X-Message-Id';

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
     * 6. Replace auto-generated X-Message-Stamp-MessageIdStamp with X-Message-Id
     *
     * @return array<string, mixed>
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        $fqn = $message::class;

        // Extract semantic name from #[MessageName] attribute (cached per class)
        $semanticName = MessageName::fromClass($message)
            ?? throw new \RuntimeException(sprintf('Message %s must have #[MessageName] attribute', $fqn));

        // Add MessageNameStamp if not present (avoid duplicates on retry)
        $existingStamp = $envelope->last(MessageNameStamp::class);
        if (!$existingStamp instanceof MessageNameStamp) {
            $envelope = $envelope->with(new MessageNameStamp($semanticName));
        }

        // Parent encode produces FQN in 'type' header and auto-serialises stamps
        $encoded = parent::encode($envelope);

        // Preserve FQN and replace 'type' with semantic name
        $headers = $encoded['headers'] ?? [];
        $headers['X-Message-Class'] = $fqn;
        $headers['type'] = $semanticName;

        // Replace auto-generated stamp header with semantic X-Message-Id
        $messageIdStamp = $envelope->last(MessageIdStamp::class);
        if ($messageIdStamp instanceof MessageIdStamp) {
            $headers[self::MESSAGE_ID_HEADER] = $messageIdStamp->messageId;
            unset($headers['X-Message-Stamp-' . MessageIdStamp::class]);
        }

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
     * 5. Read X-Message-Id header and attach MessageIdStamp
     *
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $headers = $encodedEnvelope['headers'] ?? [];

        $semanticName = $headers['type'] ?? null;
        $fqn = $headers['X-Message-Class'] ?? null;

        // Restore FQN if we have semantic name (identified by lack of backslash)
        if (is_string($semanticName) && is_string($fqn) && !str_contains($semanticName, '\\')) {
            // Replace 'type' header with FQN for parent decode
            $encodedEnvelope['headers']['type'] = $fqn;
        }

        // Extract message ID from semantic header
        $messageId = isset($headers[self::MESSAGE_ID_HEADER]) && is_string($headers[self::MESSAGE_ID_HEADER])
            ? $headers[self::MESSAGE_ID_HEADER]
            : null;

        // Strip auto-generated stamp header so parent doesn't try to deserialise it
        unset($encodedEnvelope['headers']['X-Message-Stamp-' . MessageIdStamp::class]);

        // Decode with FQN
        $envelope = parent::decode($encodedEnvelope);

        // Attach semantic name stamp for future encode() (avoid duplicates on retry)
        if (is_string($semanticName) && !str_contains($semanticName, '\\')) {
            $existingStamp = $envelope->last(MessageNameStamp::class);
            if (!$existingStamp instanceof MessageNameStamp) {
                $envelope = $envelope->with(new MessageNameStamp($semanticName));
            }
        }

        // Attach MessageIdStamp from X-Message-Id header
        if ($messageId !== null && !$envelope->last(MessageIdStamp::class) instanceof MessageIdStamp) {
            $envelope = $envelope->with(new MessageIdStamp($messageId));
        }

        return $envelope;
    }

}
