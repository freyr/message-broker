<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox\Serializer;

use Freyr\MessageBroker\Inbox\Message\InboxEventMessage;
use Freyr\MessageBroker\Inbox\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Inbox\Stamp\MessageNameStamp;
use Freyr\MessageBroker\Inbox\Stamp\SourceQueueStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface as MessengerSerializerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Inbox Serializer.
 *
 * Deserializes inbox messages into typed PHP objects using message type mapping.
 * Falls back to simple stdClass if message type is not registered.
 *
 * Uses Symfony Serializer with custom normalizers for proper type handling.
 */
final readonly class InboxSerializer implements MessengerSerializerInterface
{
    /**
     * @param array<string, class-string> $messageTypes Mapping of message_name => PHP class
     * @param SerializerInterface $serializer Symfony serializer with custom normalizers
     */
    public function __construct(
        private array $messageTypes = [],
        private SerializerInterface $serializer,
    ) {
    }

    /**
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? throw new MessageDecodingFailedException('Missing body');

        if (!is_string($body)) {
            throw new MessageDecodingFailedException('Body must be a string');
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new MessageDecodingFailedException('Decoded data must be an array');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        // Extract required fields
        $messageName = $data['message_name'] ?? throw new MessageDecodingFailedException('Missing message_name');
        $payload = $data['payload'] ?? throw new MessageDecodingFailedException('Missing payload');
        $messageId = $data['message_id'] ?? throw new MessageDecodingFailedException('Missing message_id');
        $sourceQueue = $data['source_queue'] ?? 'unknown';

        if (!is_string($messageName)) {
            throw new MessageDecodingFailedException('message_name must be a string');
        }

        if (!is_array($payload)) {
            throw new MessageDecodingFailedException('payload must be an array');
        }

        if (!is_string($messageId)) {
            throw new MessageDecodingFailedException('message_id must be a string');
        }

        if (!is_string($sourceQueue)) {
            throw new MessageDecodingFailedException('source_queue must be a string');
        }

        // Look up message class
        $messageClass = $this->messageTypes[$messageName] ?? null;

        if ($messageClass === null) {
            // Fallback: create generic stdClass with payload as properties
            $message = (object) $payload;
        } else {
            // Deserialize into typed object using Symfony Serializer
            $message = $this->serializer->denormalize($payload, $messageClass);
        }

        // Return envelope with stamps
        return new Envelope($message, [
            new MessageNameStamp($messageName),
            new MessageIdStamp($messageId),
            new SourceQueueStamp($sourceQueue),
            new BusNameStamp('messenger.bus.default'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        // Extract stamps
        $messageNameStamp = $envelope->last(MessageNameStamp::class);
        $messageIdStamp = $envelope->last(MessageIdStamp::class);
        $sourceQueueStamp = $envelope->last(SourceQueueStamp::class);

        if (!$messageNameStamp instanceof MessageNameStamp) {
            throw new \RuntimeException('Message must have MessageNameStamp');
        }

        if (!$messageIdStamp instanceof MessageIdStamp) {
            throw new \RuntimeException('Message must have MessageIdStamp');
        }

        if (!$sourceQueueStamp instanceof SourceQueueStamp) {
            throw new \RuntimeException('Message must have SourceQueueStamp');
        }

        // Special handling for InboxEventMessage - it's a DTO wrapper
        if ($message instanceof InboxEventMessage) {
            $payload = $message->payload;
        } else {
            // Serialize typed message back to payload using Symfony Serializer
            $payload = $this->serializer->normalize($message);
        }

        $body = json_encode([
            'message_name' => $messageNameStamp->messageName,
            'payload' => $payload,
            'message_id' => $messageIdStamp->messageId,
            'source_queue' => $sourceQueueStamp->sourceQueue,
        ], JSON_THROW_ON_ERROR);

        return [
            'body' => $body,
            'headers' => [
                'message_id' => $messageIdStamp->messageId,
                'message_name' => $messageNameStamp->messageName,
            ],
        ];
    }
}
