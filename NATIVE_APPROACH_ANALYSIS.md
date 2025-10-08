# Native Symfony Messenger Approach Analysis

## Executive Summary

**Recommendation**: Use **native Symfony Serializer with `type` header** if:
- ✅ All services in your ecosystem use this package
- ✅ No external/third-party integrations needed
- ✅ Want maximum simplicity and Symfony standards

**Keep current approach** if:
- ✅ Integrating with external systems (other orgs, languages)
- ✅ Want semantic, language-agnostic message names
- ✅ Need clean integration contracts

## Native Implementation

### Changes Required

#### 1. Remove Custom Serializers
```yaml
# messenger.yaml - Use native Symfony Serializer
transports:
    amqp_orders:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        # serializer: 'Freyr\MessageBroker\Inbox\Serializer\InboxSerializer' ❌
        # Use default Symfony Serializer (or specify @serializer) ✅
```

#### 2. Publish with Stamps

```php
// OutboxToAmqpBridge or custom middleware
use Freyr\MessageBroker\Inbox\MessageIdStamp;

$messageId = $event->messageId->__toString();
$envelope = new Envelope($event, [
    new MessageIdStamp($messageId),
    new AmqpStamp($routingKey, AMQP_NOPARAM, $headers),
    new TransportNamesStamp(['amqp']),
]);

$this->eventBus->dispatch($envelope);
```

#### 3. Stamps Auto-Transported
Symfony automatically:
- Serializes stamps to AMQP headers: `X-Message-Stamp-MessageIdStamp`
- Deserializes stamps from headers on receive
- DeduplicationMiddleware finds MessageIdStamp automatically

#### 4. Remove Mapping Configuration
No need for:
```yaml
# ❌ Not needed anymore
message_broker:
    inbox:
        message_types:
            'order.placed': 'App\Message\OrderPlaced'
```

### Message Format Comparison

#### Current (Canonical)
```
Headers:
  message_name: order.placed
  message_id: 01234567-89ab...

Body:
{
  "message_name": "order.placed",
  "message_id": "01234567-89ab...",
  "payload": {
    "orderId": "...",
    "totalAmount": 123.45
  }
}
```

#### Native (Symfony Standard)
```
Headers:
  type: App\Domain\Event\OrderPlaced
  X-Message-Stamp-MessageIdStamp: [{"messageId":"01234567..."}]

Body:
{
  "messageId": "01234567-89ab...",
  "orderId": "...",
  "totalAmount": 123.45,
  "placedAt": "2025-10-08T..."
}
```

## Flow Diagrams

### Current Flow
```
Outbox Transport → OutboxSerializer.encode()
  ↓ Creates: {message_name, message_id, payload}
  ↓ Headers: message_name, message_id
AMQP Transport (publishes)
  ↓
Consumer → InboxSerializer.decode()
  ↓ Extracts message_name, looks up PHP class
  ↓ Deserializes payload → typed object
  ↓ Adds MessageIdStamp manually
DeduplicationMiddleware
  ↓ Finds MessageIdStamp
  ↓ Checks deduplication table
Handler
```

### Native Flow
```
Outbox Transport
  ↓ Middleware adds MessageIdStamp to envelope
  ↓
AMQP Transport → Native Symfony Serializer
  ↓ Serializes entire object
  ↓ Adds type header = FQN
  ↓ Serializes stamps to X-Message-Stamp-* headers
AMQP (published)
  ↓
Consumer → Native Symfony Serializer
  ↓ Reads type header
  ↓ Deserializes body → typed object
  ↓ Deserializes stamps from headers (automatic!)
DeduplicationMiddleware
  ↓ Finds MessageIdStamp (already there!)
  ↓ Checks deduplication table
Handler
```

## Code Reduction Estimate

### Files to Remove/Simplify
- ❌ `src/Inbox/Serializer/InboxSerializer.php` (150 lines)
- ❌ `src/Outbox/Serializer/OutboxSerializer.php` (155 lines)
- ✂️  `src/Outbox/EventBridge/OutboxToAmqpBridge.php` (simplified, -30 lines)
- ❌ `message_types` configuration requirement

**Total reduction**: ~300-350 lines of custom code

### New Files Needed
- Middleware to extract messageId and add MessageIdStamp (50 lines)
- Or extend OutboxToAmqpBridge to add stamp (10 lines)

**Net reduction**: ~250-300 lines

## Performance Impact

### Native Approach
- ✅ Fewer serialization steps
- ✅ Stamps handled natively (optimized)
- ✅ No custom mapping lookup

### Current Approach
- ➖ Extra serialization step (payload wrapping)
- ➖ Mapping lookup (array access, negligible)
- ➖ Manual stamp creation

**Verdict**: Native approach is slightly faster, but difference is negligible (<1ms per message)

## Migration Strategy

If switching to native approach:

### Phase 1: Support Both Formats
1. Add format detection in serializer
2. Try native deserialization first (check for `type` header)
3. Fall back to canonical format
4. Publish in both formats temporarily

### Phase 2: Migrate Consumers
1. Update all consumers to handle native format
2. Monitor for errors
3. Keep fallback active

### Phase 3: Migrate Publishers
1. Switch publishers to native format only
2. Remove fallback code
3. Remove old serializers

## Decision Matrix

| Criteria | Native | Canonical (Current) |
|----------|--------|---------------------|
| Symfony Native | ✅✅✅ | ⚠️ |
| External Integration | ❌ | ✅✅✅ |
| Code Simplicity | ✅✅✅ | ⚠️ |
| Language Agnostic | ❌ | ✅✅✅ |
| Maintenance | ✅✅ | ⚠️ |
| Learning Curve | ✅ | ⚠️ |

## Final Recommendation

### Choose Native If:
1. **Closed ecosystem**: All services use this package
2. **PHP-only**: No other language consumers
3. **Want simplicity**: Minimize custom code
4. **Symfony standards**: Follow framework conventions

### Keep Canonical If:
1. **Open integration**: External systems consume messages
2. **Multi-language**: Non-PHP consumers exist
3. **Semantic naming**: Want framework-agnostic names
4. **Contract-first**: API/message contracts before implementation

## Example: Minimal Native Implementation

```php
// 1. Middleware to add MessageIdStamp on publish
class MessageIdStampMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        // Extract messageId from object property
        if (property_exists($message, 'messageId')) {
            $messageId = $message->messageId;
            $envelope = $envelope->with(
                new MessageIdStamp($messageId->__toString())
            );
        }

        return $stack->next()->handle($envelope, $stack);
    }
}

// 2. Configuration - use native serializer
// messenger.yaml
framework:
    messenger:
        transports:
            amqp_orders:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                # No serializer specified = use default Symfony Serializer

// 3. That's it! Stamps automatically transported via headers
```

## Conclusion

The native approach is **significantly simpler** and more aligned with Symfony patterns.

**Main trade-off**: Coupling external consumers to your PHP class names.

For **internal microservices**, native approach is highly recommended.

For **external integration**, keep canonical format with semantic names.
