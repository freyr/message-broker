<?php

declare(strict_types=1);

namespace Freyr\Messenger\Outbox\Serializer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use ReflectionClass;
use ReflectionNamedType;
use Freyr\Messenger\Outbox\MessageName;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Outbox Event Serializer.
 *
 * Serializes domain events to JSON with semantic event names.
 */
final readonly class OutboxEventSerializer implements SerializerInterface
{
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

        $event = $this->hydrateEvent($eventClass, $payload);

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
        $payload = $this->serializeEvent($event);

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

    /**
     * @return array<string, mixed>
     */
    private function serializeEvent(object $event): array
    {
        $reflection = new ReflectionClass($event);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        /** @var array<string, mixed> $data */
        $data = [];
        foreach ($properties as $property) {
            $name = $property->getName();
            $value = $property->getValue($event);
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
            is_object($value) => throw new \RuntimeException(
                sprintf('Cannot serialize object of type %s', $value::class)
            ),
            default => $value,
        };
    }

    /**
     * @param class-string $eventClass
     * @param array<string, mixed> $payload
     */
    private function hydrateEvent(string $eventClass, array $payload): object
    {
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($eventClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new MessageDecodingFailedException("Event {$eventClass} has no constructor");
        }

        /** @var array<int, mixed> $parameters */
        $parameters = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $value = $payload[$name] ?? null;

            if ($value === null && !$param->allowsNull()) {
                throw new MessageDecodingFailedException("Missing required parameter: {$name}");
            }

            $type = $param->getType();
            if ($type instanceof ReflectionNamedType) {
                $value = $this->deserializeValue($value, $type);
            }

            $parameters[] = $value;
        }

        return $reflection->newInstanceArgs($parameters);
    }

    private function deserializeValue(mixed $value, ReflectionNamedType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        $typeName = $type->getName();

        return match ($typeName) {
            Id::class => is_string($value)
                ? Id::fromString($value)
                : throw new MessageDecodingFailedException('Id value must be a string'),
            CarbonImmutable::class => is_string($value)
                ? CarbonImmutable::parse($value)
                : throw new MessageDecodingFailedException('CarbonImmutable value must be a string'),
            \DateTimeImmutable::class => is_string($value)
                ? new \DateTimeImmutable($value)
                : throw new MessageDecodingFailedException('DateTimeImmutable value must be a string'),
            'int' => is_int($value) ? $value : (is_numeric($value) ? (int) $value : throw new MessageDecodingFailedException('Value cannot be cast to int')),
            'float' => is_float($value) ? $value : (is_numeric($value) ? (float) $value : throw new MessageDecodingFailedException('Value cannot be cast to float')),
            'bool' => (bool) $value,
            'string' => is_string($value) ? $value : (is_scalar($value) ? (string) $value : throw new MessageDecodingFailedException('Value cannot be cast to string')),
            'array' => (array) $value,
            default => is_a($typeName, \BackedEnum::class, true)
                ? (is_int($value) || is_string($value)
                    ? $typeName::from($value)
                    : throw new MessageDecodingFailedException('Enum value must be int or string'))
                : $value,
        };
    }
}
