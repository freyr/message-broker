# Inbox Message Mapping Guide

## Overview

The inbox can automatically deserialize JSON payloads into typed PHP message objects using Symfony Messenger's built-in serialization features.

## Architecture

### Current Flow (Generic)
```
AMQP JSON → InboxEventMessage (generic wrapper) → Handler (receives array)
```

### Improved Flow (Typed)
```
AMQP JSON → Symfony Serializer → Typed Message Object → Handler (receives object)
```

## Implementation Options

### Option 1: Using Symfony Serializer Component

The inbox serializer can be enhanced to use Symfony's Serializer component to deserialize into typed objects.

#### Step 1: Configure Message Type Mapping

Create a message type map in your configuration:

```yaml
# config/services.yaml
parameters:
    inbox_message_types:
        'order.placed': 'App\Message\OrderPlaced'
        'order.cancelled': 'App\Message\OrderCancelled'
        'user.registered': 'App\Message\UserRegistered'

services:
    Freyr\Messenger\Inbox\Serializer\InboxEventSerializer:
        arguments:
            $messageTypes: '%inbox_message_types%'
            $serializer: '@serializer'
```

#### Step 2: Define Your Message Classes

Create message classes that mirror the JSON structure:

```php
<?php

namespace App\Message;

use Freyr\Identity\Id;
use Carbon\CarbonImmutable;

final readonly class OrderPlaced
{
    public function __construct(
        public Id $orderId,
        public Id $customerId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

#### Step 3: Enhanced Inbox Serializer

```php
use Symfony\Component\Serializer\SerializerInterface;

final readonly class InboxEventSerializer implements SerializerInterface
{
    public function __construct(
        private array $messageTypes,
        private SerializerInterface $serializer,
    ) {}

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'];
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $messageName = $decoded['message_name'];
        $payload = $decoded['payload'];

        // Look up the PHP class for this message_name
        $messageClass = $this->messageTypes[$messageName] ?? null;

        if ($messageClass === null) {
            // Fallback to generic InboxEventMessage
            return new Envelope(
                new InboxEventMessage($messageName, $payload, $decoded['message_id'], $decoded['source_queue'])
            );
        }

        // Deserialize into typed object using Symfony Serializer
        $message = $this->serializer->deserialize(
            json_encode($payload),
            $messageClass,
            'json'
        );

        return new Envelope($message, [
            new MessageNameStamp($messageName),
            new MessageIdStamp($decoded['message_id']),
        ]);
    }
}
```

#### Step 4: Typed Message Handlers

Now your handlers receive typed objects:

```php
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OrderPlacedHandler
{
    public function __invoke(OrderPlaced $message): void
    {
        // Type-safe access to properties
        $orderId = $message->orderId;
        $customerId = $message->customerId;
        $amount = $message->totalAmount;

        // Process the order...
    }
}
```

### Option 2: Using Messenger's Native Type Mapping

Symfony Messenger can route messages by class name. Configure routing:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            inbox:
                dsn: 'inbox://default?queue_name=inbox'
                serializer: 'messenger.transport.symfony_serializer'
                options:
                    auto_setup: true

        routing:
            'App\Message\OrderPlaced': inbox
            'App\Message\OrderCancelled': inbox
            'App\Message\UserRegistered': inbox
```

## Benefits

✅ **Type Safety**: Handlers receive typed objects instead of arrays
✅ **IDE Support**: Full autocomplete and type checking
✅ **Validation**: Symfony Serializer can validate data during deserialization
✅ **Reusability**: Same message classes used for outbox and inbox
✅ **No Boilerplate**: No need for manual hydration logic

## Sender-Receiver Contract

For this to work, sender and receiver must agree on:

1. **Message Name**: Semantic identifier (e.g., `order.placed`)
2. **Payload Structure**: Field names and types
3. **PHP Class Name**: Optional - receivers can define their own

### Example Contract

**Publisher (Outbox):**
```php
#[MessageName('order.placed')]
final readonly class OrderPlaced
{
    public function __construct(
        public Id $orderId,
        public float $amount,
    ) {}
}
```

**Consumer (Inbox):**
```php
// Can use same class or different one with compatible structure
namespace App\Message;

final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,  // Receives as string, converts if needed
        public float $amount,
    ) {}
}
```

## Migration Path

You can gradually migrate from generic handlers to typed handlers:

1. Start with generic `InboxEventMessage` + array handlers
2. Add message type mappings one at a time
3. Update handlers to use typed objects
4. Eventually remove generic fallback

## Configuration Example

```yaml
# config/services.yaml
services:
    # Message type registry
    inbox.message_registry:
        class: Freyr\Messenger\Inbox\MessageRegistry
        arguments:
            $mappings:
                - { message_name: 'order.placed', class: 'App\Message\OrderPlaced' }
                - { message_name: 'order.cancelled', class: 'App\Message\OrderCancelled' }
                - { message_name: 'user.registered', class: 'App\Message\UserRegistered' }

    # Enhanced serializer
    Freyr\Messenger\Inbox\Serializer\InboxEventSerializer:
        arguments:
            $messageRegistry: '@inbox.message_registry'
            $serializer: '@serializer'
```

## Best Practices

1. **Version your messages**: Add version field for backward compatibility
2. **Use value objects**: Id, Money, etc. for type safety
3. **Document contracts**: Maintain message schemas
4. **Test deserialization**: Ensure JSON → Object works correctly
5. **Handle missing fields**: Use nullable types or default values
