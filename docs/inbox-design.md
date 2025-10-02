# Inbox Pattern Redesign

## Problem
Current implementation uses custom `inbox_messages` table and manual processing, which duplicates Symfony Messenger's Doctrine transport functionality.

## Solution
Use Messenger's built-in Doctrine transport (`messenger_messages` table) for inbox processing.

### Approach

**AMQP → Messenger Inbox:**
```php
// ConsumeAmqpToInboxCommand
$message = new InboxEnvelope($eventName, $payload, $eventId);
$messageBus->dispatch($message);  // → routes to 'inbox' transport
```

**Messenger Inbox → Handlers:**
```bash
bin/console messenger:consume inbox
```

### Messenger Table Structure
```sql
messenger_messages:
- id (bigint)
- body (longtext) -- serialized message
- headers (longtext) -- JSON with metadata
- queue_name (varchar) -- maps to source queue!
- created_at (datetime)
- available_at (datetime)
- delivered_at (datetime)
```

### Deduplication Strategy

**Option A: Use headers for message_id**
```php
$envelope = new Envelope($message, [
    new UniqueIdStamp($eventId->__toString()),
]);
```
Then check in handler if already processed.

**Option B: Separate deduplication table**
```sql
inbox_processed_events:
- message_id (UUID, PK)
- processed_at (datetime)
```

**Option C: Use custom Messenger middleware for deduplication**
```php
class DeduplicationMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $eventId = // extract from envelope
        if ($this->isAlreadyProcessed($eventId)) {
            return $envelope; // Skip processing
        }

        $result = $stack->next()->handle($envelope, $stack);
        $this->markAsProcessed($eventId);
        return $result;
    }
}
```

## Recommendation

Use Messenger's infrastructure + **Option C (Middleware)** for deduplication.

**Benefits:**
- ✅ Leverage Messenger's SKIP LOCKED implementation
- ✅ Use standard `messenger:consume` command
- ✅ Automatic retry/failed handling
- ✅ Built-in monitoring via `messenger:stats`
- ✅ Less custom code

**Trade-offs:**
- Need separate deduplication tracking (middleware or table)
- Less control over table schema
