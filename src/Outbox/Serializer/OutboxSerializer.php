<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Serializer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use ReflectionClass;
use Freyr\MessageBroker\Outbox\MessageName;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface as MessengerSerializerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Outbox Serializer.
 *
 * Serializes domain events to JSON with semantic event names.
 * Uses Symfony Serializer with custom normalizers for proper type handling.
 */
final readonly class OutboxSerializer implements MessengerSerializerInterface
{
    public function __construct(
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

        /** @var array<string, mixed> $data - json_decode with true returns string keys */
        $data = $decoded;

        $messageName = $data['message_name'] ?? $data['event'] ?? throw new MessageDecodingFailedException('Missing message_name');
        $eventClass = $data['event_class'] ?? throw new MessageDecodingFailedException('Missing event_class');

        if (!is_string($eventClass)) {
            throw new MessageDecodingFailedException('Event class must be a string');
        }

        if (!class_exists($eventClass)) {
            throw new MessageDecodingFailedException("Event class {$eventClass} does not exist");
        }

        $rawPayload = $data['payload'] ?? [];

        if (!is_array($rawPayload)) {
            throw new MessageDecodingFailedException('Payload must be an array');
        }

        /** @var array<string, mixed> $payload - json_decode with true returns string keys */
        $payload = $rawPayload;

        $event = $this->serializer->denormalize($payload, $eventClass);

        return new Envelope($event, [
            new BusNameStamp('event.bus'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function encode(Envelope $envelope): array
    {
        $event = $envelope->getMessage();
        $messageName = $this->extractMessageName($event);
        $messageId = $this->extractMessageId($event);
        $payload = $this->serializer->normalize($event);

        $body = json_encode([
            'message_name' => $messageName,
            'message_id' => $messageId->__toString(),
            'event_class' => $event::class,
            'payload' => $payload,
            'occurred_at' => (new CarbonImmutable())->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        return [
            'body' => $body,
            'headers' => [
                'message_name' => $messageName,
                'message_id' => $messageId->__toString(),
                'event_class' => $event::class,
            ],
        ];
    }

    private function extractMessageName(object $event): string
    {
        $reflection = new ReflectionClass($event);
        $attributes = $reflection->getAttributes(MessageName::class);

        if (empty($attributes)) {
            throw new \RuntimeException(
                sprintf('Event %s must have #[MessageName] attribute', $event::class)
            );
        }

        /** @var MessageName $messageNameAttr */
        $messageNameAttr = $attributes[0]->newInstance();

        return $messageNameAttr->name;
    }

    private function extractMessageId(object $event): Id
    {
        $reflection = new ReflectionClass($event);

        // Try to find messageId property
        if (!$reflection->hasProperty('messageId')) {
            throw new \RuntimeException(
                sprintf('Event %s must have a public messageId property of type Id', $event::class)
            );
        }

        $property = $reflection->getProperty('messageId');

        if (!$property->isPublic()) {
            throw new \RuntimeException(
                sprintf('Property messageId in event %s must be public', $event::class)
            );
        }

        $messageId = $property->getValue($event);

        if (!$messageId instanceof Id) {
            throw new \RuntimeException(
                sprintf('Property messageId in event %s must be of type %s, got %s',
                    $event::class,
                    Id::class,
                    get_debug_type($messageId)
                )
            );
        }

        return $messageId;
    }
}
