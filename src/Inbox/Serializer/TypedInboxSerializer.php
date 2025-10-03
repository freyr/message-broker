<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox\Serializer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Inbox\Stamp\MessageNameStamp;
use Freyr\MessageBroker\Inbox\Stamp\SourceQueueStamp;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Typed Inbox Serializer.
 *
 * Deserializes inbox messages into typed PHP objects using message type mapping.
 * Falls back to simple stdClass if message type is not registered.
 */
final readonly class TypedInboxSerializer implements SerializerInterface
{
    /**
     * @param array<string, class-string> $messageTypes Mapping of message_name => PHP class
     */
    public function __construct(
        private array $messageTypes = [],
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
            // Deserialize into typed object
            $message = $this->hydrate($messageClass, $payload);
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

        // Serialize message back to payload
        $payload = $this->serializeMessage($message);

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

    /**
     * Hydrate payload into typed message object.
     *
     * @param class-string $messageClass
     * @param array<string, mixed> $payload
     */
    private function hydrate(string $messageClass, array $payload): object
    {
        if (!class_exists($messageClass)) {
            throw new MessageDecodingFailedException("Message class {$messageClass} does not exist");
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($messageClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new MessageDecodingFailedException("Message class {$messageClass} has no constructor");
        }

        /** @var array<int, mixed> $parameters */
        $parameters = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $value = $payload[$name] ?? null;

            if ($value === null && !$param->allowsNull()) {
                if ($param->isDefaultValueAvailable()) {
                    $value = $param->getDefaultValue();
                } else {
                    throw new MessageDecodingFailedException("Missing required parameter: {$name} in {$messageClass}");
                }
            }

            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $value !== null) {
                $value = $this->deserializeValue($value, $type);
            }

            $parameters[] = $value;
        }

        return $reflection->newInstanceArgs($parameters);
    }

    private function deserializeValue(mixed $value, ReflectionNamedType $type): mixed
    {
        $typeName = $type->getName();

        return match ($typeName) {
            Id::class => is_string($value) ? Id::fromString($value) : throw new MessageDecodingFailedException('Id value must be a string'),
            CarbonImmutable::class => is_string($value) ? CarbonImmutable::parse($value) : throw new MessageDecodingFailedException('CarbonImmutable value must be a string'),
            \DateTimeImmutable::class => is_string($value) ? new \DateTimeImmutable($value) : throw new MessageDecodingFailedException('DateTimeImmutable value must be a string'),
            'int' => is_int($value) ? $value : (is_numeric($value) ? (int) $value : throw new MessageDecodingFailedException('Value cannot be cast to int')),
            'float' => is_float($value) ? $value : (is_numeric($value) ? (float) $value : throw new MessageDecodingFailedException('Value cannot be cast to float')),
            'bool' => (bool) $value,
            'string' => is_string($value) ? $value : (is_scalar($value) ? (string) $value : throw new MessageDecodingFailedException('Value cannot be cast to string')),
            'array' => (array) $value,
            default => is_a($typeName, \BackedEnum::class, true)
                ? (is_int($value) || is_string($value) ? $typeName::from($value) : throw new MessageDecodingFailedException('Enum value must be int or string'))
                : $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(object $message): array
    {
        if ($message instanceof \stdClass) {
            return (array) $message;
        }

        $reflection = new ReflectionClass($message);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        /** @var array<string, mixed> $data */
        $data = [];
        foreach ($properties as $property) {
            $name = $property->getName();
            $value = $property->getValue($message);
            $data[$name] = $this->serializeValue($value);
        }

        return $data;
    }

    private function serializeValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Id => $value->__toString(),
            $value instanceof CarbonImmutable => $value->toIso8601String(),
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \BackedEnum => $value->value,
            is_array($value) => array_map($this->serializeValue(...), $value),
            is_object($value) => throw new \RuntimeException(sprintf('Cannot serialize object of type %s', $value::class)),
            default => $value,
        };
    }
}
