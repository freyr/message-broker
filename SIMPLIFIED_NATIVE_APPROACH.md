# Simplified Native Approach: "Fake FQN" Pattern

## Executive Summary

**The Insight**: Symfony's Serializer requires a `type` header for class resolution, but there's nothing preventing us from putting a **semantic name** there and translating it to FQN in a minimal wrapper.

**Result**:
- ✅ Keep semantic message names (`order.placed`)
- ✅ Use native Symfony stamp handling (automatic!)
- ✅ Dramatically simplified serializers (~50 lines total vs ~300)
- ✅ External systems still see semantic names

---

## How It Works

### Publishing Flow

```
Domain Event (with #[MessageName('order.placed')])
  ↓
OutboxToAmqpBridge adds MessageIdStamp
  ↓
SimplifiedOutboxSerializer:
  1. Calls parent.encode() → serializes body + stamps
  2. Overrides type header: type='order.placed' (not FQN!)
  ↓
AMQP Message:
  Headers:
    type: 'order.placed'  ← Semantic name!
    X-Message-Stamp-MessageIdStamp: [{"messageId":"..."}]  ← Native!
  Body:
    {"messageId":"...","orderId":"...","totalAmount":123.45}
```

### Consuming Flow

```
AMQP Message received
  ↓
SimplifiedInboxSerializer:
  1. Reads type='order.placed'
  2. Looks up FQN: 'App\Message\OrderPlaced'
  3. Replaces type header with FQN
  4. Calls parent.decode() → handles EVERYTHING natively
  ↓
Envelope created with:
  - Message: Deserialized App\Message\OrderPlaced object
  - Stamps: MessageIdStamp (deserialized from X-Message-Stamp-* header)
  ↓
DeduplicationMiddleware finds MessageIdStamp (no extraction needed!)
  ↓
Handler
```

---

## Code Comparison

### Current InboxSerializer: 147 lines
```php
// Complex custom logic:
- JSON parsing
- Field extraction (message_name, message_id, payload)
- Manual stamp creation (MessageIdStamp, MessageNameStamp)
- Custom deserialization
- Fallback handling
```

### Simplified InboxSerializer: 47 lines
```php
public function decode(array $encodedEnvelope): Envelope
{
    $messageName = $encodedEnvelope['headers']['type'];
    $fqn = $this->messageTypes[$messageName] ?? throw new Exception();

    $encodedEnvelope['headers']['type'] = $fqn;

    return parent::decode($encodedEnvelope);  // Native handles the rest!
}
```

**Reduction**: **-100 lines (-68%)**

---

### Current OutboxSerializer: 154 lines
```php
// Complex custom logic:
- Custom JSON structure (message_name, payload wrapper)
- Manual attribute extraction
- Manual messageId extraction
- Custom serialization
- Header management
```

### Simplified OutboxSerializer: 40 lines
```php
public function encode(Envelope $envelope): array
{
    $messageName = $this->extractMessageName($envelope->getMessage());

    $encoded = parent::encode($envelope);  // Native handles stamps!
    $encoded['headers']['type'] = $messageName;

    return $encoded;
}
```

**Reduction**: **-114 lines (-74%)**

---

## Key Benefits

### 1. **Stamps Handled Natively** ✨

**Before** (Manual):
```php
// InboxSerializer had to manually create stamps
return new Envelope($message, [
    new MessageIdStamp($messageId),
    new MessageNameStamp($messageName),
]);
```

**After** (Automatic):
```php
// Parent decode() automatically deserializes ALL stamps from headers
return parent::decode($encodedEnvelope);
// MessageIdStamp, MessageNameStamp, etc. all restored automatically!
```

### 2. **Simplified Bridge**

**Before**:
```php
// Had to use OutboxSerializer to extract message_name
$encoded = $this->serializer->encode(new Envelope($event));
$messageName = $encoded['headers']['message_name'];
```

**After**:
```php
// Just add the stamp - Symfony handles serialization
$envelope = new Envelope($event, [
    new MessageIdStamp($messageId->__toString()),
]);
```

### 3. **External Systems See Clean Format**

```
Headers:
  type: order.placed  ← Semantic, not PHP-specific!
  X-Message-Stamp-MessageIdStamp: [...]  ← Can be ignored by external systems

Body:
  {"messageId":"...","orderId":"..."}  ← Clean object serialization
```

---

## Message Format

### AMQP Wire Format

```json
Headers:
{
  "type": "order.placed",
  "X-Message-Stamp-MessageIdStamp": "[{\"messageId\":\"01234567-89ab...\"}]",
  "X-Message-Stamp-MessageNameStamp": "[{\"messageName\":\"order.placed\"}]"
}

Body:
{
  "messageId": "01234567-89ab-cdef-0123-456789abcdef",
  "orderId": "550e8400-e29b-41d4-a716-446655440000",
  "totalAmount": 123.45,
  "placedAt": "2025-10-08T13:30:00+00:00"
}
```

**Key Points**:
- `type` = semantic name (external systems use this)
- `X-Message-Stamp-*` = Symfony internal (external systems ignore)
- Body = clean object serialization (no wrapper)

---

## DeduplicationMiddleware Integration

### Current Approach
```php
// InboxSerializer manually creates stamp
$envelope = new Envelope($message, [
    new MessageIdStamp($messageId),  // Manual
]);

// DeduplicationMiddleware extracts it
$messageIdStamp = $envelope->last(MessageIdStamp::class);
```

### Simplified Approach
```php
// OutboxToAmqpBridge adds stamp
$envelope = new Envelope($event, [
    new MessageIdStamp($messageId->__toString()),
]);

// Symfony automatically serializes to X-Message-Stamp-MessageIdStamp header

// Consumer: Symfony automatically deserializes stamp from header

// DeduplicationMiddleware extracts it (same as before!)
$messageIdStamp = $envelope->last(MessageIdStamp::class);
```

**No changes needed to DeduplicationMiddleware!** ✅

---

## Migration Path

### Phase 1: Add Simplified Serializers
1. Create `SimplifiedInboxSerializer`
2. Create `SimplifiedOutboxSerializer`
3. Configure in services.yaml

### Phase 2: Test Both Formats
```php
// SimplifiedInboxSerializer can handle both:
public function decode(array $encodedEnvelope): Envelope
{
    $type = $encodedEnvelope['headers']['type'];

    // If already FQN, pass through
    if (str_contains($type, '\\')) {
        return parent::decode($encodedEnvelope);
    }

    // If semantic name, translate
    $fqn = $this->messageTypes[$type] ?? throw new Exception();
    $encodedEnvelope['headers']['type'] = $fqn;
    return parent::decode($encodedEnvelope);
}
```

### Phase 3: Switch Consumers
1. Update consumers to use SimplifiedInboxSerializer
2. Monitor for errors
3. Keep compatibility layer

### Phase 4: Switch Publishers
1. Update publishers to use SimplifiedOutboxSerializer
2. Remove old serializers

---

## Configuration

### services.yaml
```yaml
services:
    # Simplified Inbox Serializer
    Freyr\MessageBroker\Serializer\MessageNameSerializer:
        arguments:
            $messageTypes: '%message_broker.inbox.message_types%'

    # Simplified Outbox Serializer
    Freyr\MessageBroker\Serializer\MessageNameSerializer: ~

    # Bridge (no serializer dependency for extraction!)
    Freyr\MessageBroker\Outbox\EventBridge\SimplifiedOutboxToAmqpBridge:
        arguments:
            $eventBus: '@messenger.default_bus'
            $routingStrategy: '@Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface'
            $logger: '@logger'
```

### messenger.yaml
```yaml
framework:
    messenger:
        transports:
            amqp_orders:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\MessageBroker\Serializer\MessageNameSerializer'
```

---

## Advantages vs Current Approach

| Aspect | Current | Simplified | Winner |
|--------|---------|------------|--------|
| Lines of Code | ~300 | ~90 | ✅ Simplified |
| Stamp Handling | Manual | Native | ✅ Simplified |
| Symfony Alignment | Custom | Native + minimal override | ✅ Simplified |
| External Integration | Semantic names | Semantic names | 🟰 Equal |
| Stamp Transport | Custom logic | Native headers | ✅ Simplified |
| Complexity | High | Low | ✅ Simplified |
| Maintenance | More code | Less code | ✅ Simplified |

---

## Advantages vs Pure Native (FQN in type)

| Aspect | Pure Native | Simplified | Winner |
|--------|-------------|------------|--------|
| External Integration | Coupled to PHP | Semantic names | ✅ Simplified |
| Code Simplicity | Minimal | Very small wrapper | ✅ Native (slightly) |
| Symfony Alignment | 100% | 99% | ✅ Native (slightly) |
| Flexibility | PHP-only | Any language | ✅ Simplified |

---

## Technical Details

### How Symfony Handles Stamps

**Encoding** (line 135-146 in Symfony\Serializer):
```php
private function encodeStamps(Envelope $envelope): array
{
    $headers = [];
    foreach ($envelope->all() as $class => $stamps) {
        // Serializes stamps to X-Message-Stamp-{StampClass} header
        $headers[self::STAMP_HEADER_PREFIX.$class] =
            $this->serializer->serialize($stamps, $this->format, $this->context);
    }
    return $headers;
}
```

**Decoding** (line 114-133 in Symfony\Serializer):
```php
private function decodeStamps(array $encodedEnvelope): array
{
    $stamps = [];
    foreach ($encodedEnvelope['headers'] as $name => $value) {
        if (!str_starts_with($name, self::STAMP_HEADER_PREFIX)) {
            continue;
        }
        // Deserializes X-Message-Stamp-* headers back to stamp objects
        $stamps[] = $this->serializer->deserialize(
            $value,
            substr($name, \strlen(self::STAMP_HEADER_PREFIX)).'[]',
            $this->format,
            $this->context
        );
    }
    return $stamps;
}
```

This means:
- ✅ Any stamp is automatically transported
- ✅ No manual stamp extraction needed
- ✅ Fully native Symfony behavior

---

## Recommendation

**✅ Use Simplified Approach**

Combines the best of both worlds:
1. **Semantic names** for external integration
2. **Native Symfony** stamp handling
3. **Minimal code** (~70% reduction)
4. **Easy maintenance**
5. **Future-proof** (aligned with Symfony patterns)

The only "trick" is translating `type` header from semantic name to FQN. Everything else is 100% native Symfony.

---

## Example Usage

### Define Event
```php
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\Identity\Id;

#[MessageName('order.placed')]
final readonly class OrderPlaced
{
    public function __construct(
        public Id $messageId,
        public Id $orderId,
        public float $totalAmount,
    ) {}
}
```

### Publish
```php
// Just dispatch - bridge adds MessageIdStamp automatically
$this->eventBus->dispatch(new OrderPlaced(
    messageId: Id::generate(),
    orderId: Id::fromString('...'),
    totalAmount: 123.45,
));
```

### Consume
```php
#[AsMessageHandler]
final readonly class OrderPlacedHandler
{
    public function __invoke(OrderPlaced $message): void
    {
        // Fully typed, stamps automatically restored
        // DeduplicationMiddleware has already checked for duplicates
        $this->processOrder($message->orderId, $message->totalAmount);
    }
}
```

---

## Conclusion

The "Fake FQN" pattern is **brilliant** because it:
- ✅ Leverages Symfony's native machinery (stamps, serialization)
- ✅ Maintains semantic naming for external systems
- ✅ Reduces custom code by ~70%
- ✅ Simplifies maintenance
- ✅ No changes to DeduplicationMiddleware
- ✅ Future-proof and aligned with Symfony conventions

**Recommendation**: Migrate to this approach. It's simpler, cleaner, and more maintainable while keeping all the benefits of semantic naming.
