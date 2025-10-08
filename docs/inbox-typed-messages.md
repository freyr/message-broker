# Typed Inbox Messages Guide

## Overview

The `InboxSerializer` allows you to receive typed PHP objects instead of arrays, providing full type safety and IDE support.

## Quick Start

### 1. Define Message Classes

Create readonly message classes matching your JSON structure:

```php
<?php

namespace App\Message;

use Freyr\Identity\Id;
use Carbon\CarbonImmutable;

final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

### 2. Configure Message Type Mapping

In `config/services.yaml`:

```yaml
parameters:
    inbox.message_types:
        'order.placed': 'App\Message\OrderPlaced'
        'order.cancelled': 'App\Message\OrderCancelled'
        'user.registered': 'App\Message\UserRegistered'

services:
    Freyr\MessageBroker\Serializer\MessageNameSerializer:
        arguments:
            $messageTypes: '%inbox.message_types%'
```

### 3. Configure Inbox Transport

In `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            inbox:
                dsn: 'doctrine://default?table_name=messenger_inbox&queue_name=inbox'
                serializer: 'Freyr\MessageBroker\Serializer\MessageNameSerializer'
                options:
                    auto_setup: false  # Use migrations

        routing:
            'App\Message\OrderPlaced': inbox
            'App\Message\OrderCancelled': inbox
            'App\Message\UserRegistered': inbox
```

### 4. Create Message Handlers

Use standard Symfony Messenger handlers:

```php
<?php

namespace App\Handler;

use App\Message\OrderPlaced;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OrderPlacedHandler
{
    public function __invoke(OrderPlaced $message): void
    {
        // Type-safe access with IDE autocomplete!
        $orderId = $message->orderId;
        $customerId = $message->customerId;
        $amount = $message->totalAmount;

        // Process the order...
    }
}
```

## How It Works

### AMQP Message Format

```json
{
  "message_name": "order.placed",
  "message_id": "01234567-89ab-cdef-0123-456789abcdef",
  "payload": {
    "orderId": "550e8400-e29b-41d4-a716-446655440000",
    "customerId": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
    "totalAmount": 99.99,
    "placedAt": "2025-10-02T22:00:00+00:00"
  }
}
```

### Deserialization Flow

1. **AMQP Consumer** receives JSON message
2. **InboxSerializer** looks up `message_name` → PHP class mapping
3. **Hydrator** deserializes `payload` into typed object
4. **Stamps attached**: `MessageNameStamp` and `MessageIdStamp`
5. **Messenger** routes to handler based on class name
6. **Handler** receives typed object

## Supported Types

The serializer uses Symfony's native `@serializer` service and automatically handles:

- **Primitives**: `string`, `int`, `float`, `bool`, `array`
- **Value Objects**: `Freyr\Identity\Id` (via built-in `IdNormalizer`)
- **Dates**: `Carbon\CarbonImmutable` (via built-in `CarbonImmutableNormalizer`), `\DateTimeImmutable`
- **Enums**: PHP 8.1+ BackedEnums
- **Nullable types**: Optional constructor parameters
- **Custom types**: Via your own normalizers (see below)

### Adding Custom Type Support

To add serialization support for your own types, create a normalizer:

```php
namespace App\Serializer\Normalizer;

use App\ValueObject\Money;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class MoneyNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        return [
            'amount' => $object->getAmount(),
            'currency' => $object->getCurrency(),
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Money;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Money
    {
        return new Money($data['amount'], $data['currency']);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === Money::class;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Money::class => true];
    }
}
```

Register it with Symfony's serializer:

```yaml
services:
    App\Serializer\Normalizer\MoneyNormalizer:
        tags: ['serializer.normalizer']
```

Your custom types will now be automatically serialized/deserialized in inbox messages!

### Example with Value Objects

```php
final readonly class OrderPlaced
{
    public function __construct(
        public Id $orderId,                    // Will be deserialized from string
        public Id $customerId,                  // Will be deserialized from string
        public float $totalAmount,
        public CarbonImmutable $placedAt,       // Will be parsed from ISO 8601
        public ?string $notes = null,           // Nullable with default
    ) {}
}
```

## Fallback Behavior

If a `message_name` is not in the mapping, the serializer creates a generic `\stdClass` object with payload fields as properties. This allows processing unknown messages:

```php
#[AsMessageHandler]
final class GenericMessageHandler
{
    public function __invoke(\stdClass $message): void
    {
        // Access properties dynamically
        $data = (array) $message;
        // Handle unknown message type...
    }
}
```

## Access to Metadata

Use stamps to access message metadata:

```php
use Freyr\MessageBroker\Inbox\MessageIdStamp;use Freyr\MessageBroker\Inbox\MessageNameStamp;use Symfony\Component\Messenger\Envelope;

#[AsMessageHandler]
final class OrderPlacedHandler
{
    public function __invoke(OrderPlaced $message, Envelope $envelope): void
    {
        // Get message name
        $messageNameStamp = $envelope->last(MessageNameStamp::class);
        if ($messageNameStamp instanceof MessageNameStamp) {
            $messageName = $messageNameStamp->messageName; // 'order.placed'
        }

        // Get message ID for tracing
        $messageIdStamp = $envelope->last(MessageIdStamp::class);
        if ($messageIdStamp instanceof MessageIdStamp) {
            $messageId = $messageIdStamp->messageId;
        }

        // Process message...
    }
}
```

## Benefits

✅ **Type Safety**: Compile-time type checking, no more array access errors
✅ **IDE Support**: Full autocomplete, refactoring, and navigation
✅ **Documentation**: Types serve as self-documentation
✅ **Validation**: Constructor validates required fields automatically
✅ **Testability**: Easy to mock and test typed objects
✅ **Maintainability**: Refactoring is safe with IDE support

## Migration from Array-Based

You can gradually migrate:

1. **Add InboxSerializer** with empty message type mapping
2. **Add one message type** at a time to the mapping
3. **Update handler** to use typed parameter
4. **Test** thoroughly
5. **Repeat** for other message types

Old array-based handlers continue to work with the fallback `\stdClass`.

## Best Practices

1. **Use readonly**: Make message classes readonly for immutability
2. **Use value objects**: Leverage `Id`, `Money`, etc. for domain concepts
3. **Version messages**: Add version field for breaking changes
4. **Document structure**: Add phpdoc with JSON example
5. **Test deserialization**: Write unit tests for edge cases

## Example: Complete Setup

```php
// src/Message/OrderPlaced.php
<?php

namespace App\Message;

use Freyr\Identity\Id;
use Carbon\CarbonImmutable;

/**
 * Order Placed Event.
 *
 * JSON Structure:
 * {
 *   "orderId": "uuid",
 *   "customerId": "uuid",
 *   "totalAmount": 99.99,
 *   "placedAt": "2025-10-02T22:00:00+00:00"
 * }
 */
final readonly class OrderPlaced
{
    public function __construct(
        public Id $orderId,
        public Id $customerId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}

// src/Handler/OrderPlacedHandler.php
<?php

namespace App\Handler;

use App\Message\OrderPlaced;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
final readonly class OrderPlacedHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function __invoke(OrderPlaced $message): void
    {
        $this->logger->info('Processing order', [
            'order_id' => $message->orderId->__toString(),
            'customer_id' => $message->customerId->__toString(),
            'amount' => $message->totalAmount,
        ]);

        // Process order...
    }
}
```

## Troubleshooting

### "Missing required parameter: X"

Ensure your JSON payload has all required constructor parameters, or add default values:

```php
public function __construct(
    public string $field,
    public ?string $optional = null,  // Add default
) {}
```

### "Class does not exist"

Check your message type mapping configuration and ensure the class is autoloaded.

### Type conversion errors

The serializer handles basic type conversions (string to int, etc.), but for complex types, ensure your JSON matches the expected format.
