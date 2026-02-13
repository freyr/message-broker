<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use Freyr\MessageBroker\Stamp\MessageNameStamp;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Wire Format Serializer (for AMQP Publishing).
 *
 * Translates the internal envelope format to the external wire format:
 * - encode(): Replaces FQN with semantic name in 'type' header, preserves FQN in X-Message-Class
 * - decode(): Restores FQN from X-Message-Class header (for retry/failed scenarios)
 *
 * Stamps flow natively via X-Message-Stamp-* headers — no stripping or re-injection.
 *
 * Usage: Configure on AMQP publishing transports only.
 */
final class WireFormatSerializer extends Serializer
{
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
     * 3. Preserve FQN in X-Message-Class header (for decode on retry)
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

        $encoded = parent::encode($envelope);
        $headers = $encoded['headers'] ?? [];

        // Preserve FQN for retry/failed decode path
        $headers[self::MESSAGE_CLASS_HEADER] = $envelope->getMessage()::class;

        // Replace FQN with semantic name
        $headers['type'] = $messageNameStamp->messageName;

        $encoded['headers'] = $headers;

        /** @var array<string, mixed> $encoded */
        return $encoded;
    }

    /**
     * Decode: Restore internal format from external wire format.
     *
     * Flow:
     * 1. Replace semantic 'type' with FQN from X-Message-Class
     * 2. Clean up wire-format-specific header
     * 3. Delegate to parent::decode() for message + stamp reconstruction
     *
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        /** @var array<string, mixed> $headers */
        $headers = $encodedEnvelope['headers'] ?? [];

        $semanticName = $headers['type'] ?? null;
        $fqn = $headers[self::MESSAGE_CLASS_HEADER] ?? null;

        // Restore FQN in type header (so parent can deserialise the message class)
        if (is_string($semanticName) && is_string($fqn) && !str_contains($semanticName, '\\')) {
            $headers['type'] = $fqn;
        }

        // Clean up wire-format-specific header
        unset($headers[self::MESSAGE_CLASS_HEADER]);

        $encodedEnvelope['headers'] = $headers;

        /** @var array{body: string, headers?: array<string, string>} $encodedEnvelope */
        return parent::decode($encodedEnvelope);
    }
}
