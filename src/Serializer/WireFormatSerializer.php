<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Stamp\MessageNameStamp;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Wire Format Serializer (for AMQP Publishing).
 *
 * Translates the internal envelope format to the external wire format:
 * - encode(): Reads stamps, replaces FQN with semantic name, adds X-Message-Id header
 * - decode(): Restores FQN from X-Message-Class header (for retry/failed scenarios)
 *
 * Stamps are the single source of truth — no attribute reflection.
 *
 * Usage: Configure on AMQP publishing transports only.
 */
final class WireFormatSerializer extends Serializer
{
    private const MESSAGE_ID_HEADER = 'X-Message-Id';
    private const MESSAGE_CLASS_HEADER = 'X-Message-Class';

    /**
     * @param SerializerInterface $serializer Symfony's native @serializer service
     */
    public function __construct(SerializerInterface $serializer)
    {
        parent::__construct($serializer);
    }

    /**
     * Encode: Translate internal format to external wire format.
     *
     * Flow:
     * 1. Let parent encode (produces FQN in 'type' header, stamps in X-Message-Stamp-*)
     * 2. Read MessageNameStamp → replace 'type' with semantic name
     * 3. Read MessageIdStamp → add X-Message-Id header
     * 4. Preserve FQN in X-Message-Class header (for decode on retry)
     * 5. Strip X-Message-Stamp-* headers for MessageIdStamp and MessageNameStamp
     *
     * @return array<string, mixed>
     */
    public function encode(Envelope $envelope): array
    {
        $messageNameStamp = $envelope->last(MessageNameStamp::class);
        if (!$messageNameStamp instanceof MessageNameStamp) {
            throw new RuntimeException(sprintf(
                'Envelope for %s must contain MessageNameStamp. Ensure MessageNameStampMiddleware runs at dispatch time.',
                $envelope->getMessage()::class,
            ));
        }

        $messageIdStamp = $envelope->last(MessageIdStamp::class);
        if (!$messageIdStamp instanceof MessageIdStamp) {
            throw new RuntimeException(sprintf(
                'Envelope for %s must contain MessageIdStamp. Ensure MessageIdStampMiddleware runs at dispatch time.',
                $envelope->getMessage()::class,
            ));
        }

        $encoded = parent::encode($envelope);
        $headers = $encoded['headers'] ?? [];

        // Preserve FQN for retry/failed decode path
        $headers[self::MESSAGE_CLASS_HEADER] = $envelope->getMessage()::class;

        // Replace FQN with semantic name
        $headers['type'] = $messageNameStamp->messageName;

        // Add semantic message ID header
        $headers[self::MESSAGE_ID_HEADER] = $messageIdStamp->messageId;

        // Strip internal stamp headers (replaced by semantic headers above)
        unset($headers['X-Message-Stamp-' . MessageIdStamp::class]);
        unset($headers['X-Message-Stamp-' . MessageNameStamp::class]);

        $encoded['headers'] = $headers;

        /** @var array<string, mixed> $encoded */
        return $encoded;
    }

    /**
     * Decode: Restore internal format from external wire format.
     *
     * Flow:
     * 1. Read FQN from X-Message-Class header
     * 2. Read semantic name from 'type' header
     * 3. Read message ID from X-Message-Id header
     * 4. Replace 'type' with FQN for parent decoder
     * 5. Strip X-Message-Stamp-* for stamps we restore manually
     * 6. Attach MessageNameStamp and MessageIdStamp
     *
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        /** @var array<string, mixed> $headers */
        $headers = $encodedEnvelope['headers'] ?? [];

        $semanticName = $headers['type'] ?? null;
        $fqn = $headers[self::MESSAGE_CLASS_HEADER] ?? null;

        // Restore FQN if we have a semantic name (identified by lack of backslash)
        if (is_string($semanticName) && is_string($fqn) && !str_contains($semanticName, '\\')) {
            $headers['type'] = $fqn;
        }

        // Extract message ID from semantic header
        $messageId = isset($headers[self::MESSAGE_ID_HEADER]) && is_string($headers[self::MESSAGE_ID_HEADER])
            ? $headers[self::MESSAGE_ID_HEADER]
            : null;

        // Strip stamp headers so the parent doesn't try to deserialise them
        unset($headers['X-Message-Stamp-' . MessageIdStamp::class]);
        unset($headers['X-Message-Stamp-' . MessageNameStamp::class]);

        $encodedEnvelope['headers'] = $headers;

        /** @var array{body: string, headers?: array<string, string>} $encodedEnvelope */
        $envelope = parent::decode($encodedEnvelope);

        // Attach MessageNameStamp (avoid duplicates on retry)
        if (is_string($semanticName) && !str_contains($semanticName, '\\')) {
            if (!$envelope->last(MessageNameStamp::class) instanceof MessageNameStamp) {
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
