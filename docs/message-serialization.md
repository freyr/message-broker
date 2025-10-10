# Message Serialization with Semantic Names

## Principle

Messages are serialized using semantic, language-agnostic names (`order.placed`) in the AMQP `type` header instead of PHP class names (`App\Domain\Event\OrderPlaced`). This enables cross-language communication and decouples consumers from publisher implementation.

## How It Works

**Publishing (Outbox → AMQP):**
1. Event has `#[MessageName('order.placed')]` attribute
2. `MessageNameSerializer` extracts semantic name during encoding
3. Sets AMQP `type` header to `order.placed` (not PHP FQN)
4. Body contains JSON-serialized event payload
5. Stamps automatically serialized to `X-Message-Stamp-*` headers

**Consuming (AMQP → Inbox):**
1. Message arrives with `type: order.placed` header
2. `MessageNameSerializer` looks up mapping: `order.placed` → `App\Message\OrderPlaced`
3. Delegates to Symfony's native serializer for deserialization
4. Stamps restored from `X-Message-Stamp-*` headers
5. Result: Typed PHP object for handler

## Benefits

**Language independence:** Non-PHP consumers can understand message types

**Decoupling:** Consumers don't need publisher's class names

**Versioning:** Same semantic name can map to different classes per application

**Type safety:** Native Symfony serialization with full type support

## Architecture

```
[Publisher Event] → #[MessageName('order.placed')]
         ↓
[MessageNameSerializer::encode()]
         ↓
AMQP: { type: "order.placed", body: {...}, stamps: X-Message-Stamp-* }
         ↓
[MessageNameSerializer::decode()]
         ↓
message_types['order.placed'] → App\Message\OrderPlaced
         ↓
[Symfony Serializer] → Typed PHP Object
         ↓
[Handler(OrderPlaced $message)]
```

## Key Components

- **MessageName attribute** - Declares semantic name on event classes
- **MessageNameSerializer** - Unified serializer for both inbox and outbox
- **message_types configuration** - Maps semantic names to PHP classes
- **Symfony Serializer** - Native JSON serialization with normalizers
- **Stamp headers** - X-Message-Stamp-* for metadata transport

## Message Format

**AMQP Headers:**
- `type: order.placed` - Semantic message name
- `X-Message-Stamp-MessageIdStamp: [{"messageId":"..."}]` - Extracted from Message

**AMQP Body:**
```json
{
  "messageId": "01234567-89ab-cdef...",
  "orderId": "550e8400-e29b...",
  "amount": 99.99,
  "placedAt": "2025-10-10T10:30:00+00:00"
}
```

## Custom Type Support

**Built-in Normalizers:**
- `IdNormalizer` - For UUID v7 (Freyr\Identity\Id)
- `CarbonImmutableNormalizer` - For Carbon dates

**Adding Custom Types:**
Create normalizer/denormalizer and tag with `serializer.normalizer`:

All normalizers tagged in service container are automatically used by MessageNameSerializer since it extends Symfony's native serializer service.

## Configuration

**Define message type mapping** (config/packages/message_broker.yaml):
```yaml
message_broker:
    inbox:
        message_types:
            'order.placed': 'App\Message\OrderPlaced'
            'user.registered': 'App\Message\UserRegistered'
```

- **Publisher events** must have `#[MessageName]` attribute - no mapping needed.
- **Publisher events** must have `Freyr\Identity\Id $messageId` public property for message_id extraction
