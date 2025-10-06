# Outbox Pattern Implementation

## Overview

The Outbox Pattern ensures reliable event publishing by storing domain events in a database table (outbox) within the same transaction as business data. Events are then asynchronously published to external systems using a **strategy-based architecture** that supports multiple publishing targets.

## Architecture

```
Domain Event Dispatched
         ↓
Event Bus (Messenger)
         ↓
Outbox Transport (doctrine://)
         ↓
messenger_messages table (transactional)
         ↓
messenger:consume outbox
         ↓
OutboxToAmqpBridge (Generic Handler)
         ↓
PublishingStrategyRegistry
         ↓
    ┌────────┴────────┐
    ↓                 ↓
AmqpPublishingStrategy   [Other Strategies]
    ↓
AMQP Transport (RabbitMQ)

Unmatched Events → DLQ Transport
```

## Components

### 1. Outbox Serializer
**Location:** `src/Outbox/Serializer/OutboxSerializer.php`

- Serializes domain events to JSON with semantic event names
- **Extracts and validates `messageId` (UUID v7) from event objects** ✨
- Extracts event name from `#[MessageName]` attribute
- Handles custom types: `Id`, `CarbonImmutable`, enums
- Used for both outbox storage and publishing

**JSON Output Format:**
```json
{
  "message_name": "order.placed",
  "message_id": "01234567-89ab-cdef-0123-456789abcdef",
  "event_class": "App\\Domain\\Event\\OrderPlaced",
  "payload": { ... },
  "occurred_at": "2025-10-02T12:34:56+00:00"
}
```

### 2. Publishing Strategy System ✨ **NEW**
**Location:** `src/Outbox/Publishing/`

#### PublishingStrategyInterface
Defines contract for publishing strategies:
- `supports(object $event): bool` - Check if strategy handles this event
- `publish(object $event): void` - Publish the event
- `getName(): string` - Strategy identifier

#### PublishingStrategyRegistry
Manages collection of strategies and finds appropriate one for each event.

#### AmqpPublishingStrategy
Default AMQP publishing implementation:
- Supports all events by default (catch-all)
- Uses `AmqpRoutingStrategyInterface` for routing decisions
- Publishes to RabbitMQ via AMQP transport

### 3. Outbox Bridge (Generic Handler) ✨ **REFACTORED**
**Location:** `src/Outbox/EventBridge/OutboxToAmqpBridge.php`

- **Single generic handler** using `__invoke()` for all events
- Consumes events from outbox transport via `#[AsMessageHandler(fromTransport: 'outbox')]`
- Uses `PublishingStrategyRegistry` to find matching strategy
- **Automatically routes unmatched events to DLQ** ✨
- No need to modify for new event types

**Before (Old):**
```php
#[AsMessageHandler(fromTransport: 'outbox')]
public function handleSlaBreached(SlaBreached $event): void

#[AsMessageHandler(fromTransport: 'outbox')]
public function handleSlaCalculated(SlaCalculated $event): void
// ... 6 explicit methods
```

**After (New):**
```php
#[AsMessageHandler(fromTransport: 'outbox')]
public function __invoke(object $event): void {
    $strategy = $this->strategyRegistry->findStrategyFor($event);
    $strategy ? $strategy->publish($event) : $this->sendToDlq($event);
}
```

### 4. AMQP Routing Strategy ✨ **UPDATED**
**Location:** `src/Outbox/Routing/`

**Interface:** `AmqpRoutingStrategyInterface`
- `getExchange(object $event, string $messageName): string`
- `getRoutingKey(object $event, string $messageName): string`
- `getHeaders(string $messageName): array`

**Default Implementation:** `DefaultAmqpRoutingStrategy`
- **Convention-based:** First 2 parts of message name → exchange (`order.placed` → `order.placed`)
- **Full message name** → routing key
- **Attribute overrides:** `#[AmqpExchange('custom')]` and `#[AmqpRoutingKey('custom.key')]`

**Routing Examples:**
```php
// Default
#[MessageName('order.placed')]
// Exchange: order.placed, Routing Key: order.placed

// Override exchange
#[MessageName('order.placed')]
#[AmqpExchange('commerce')]
// Exchange: commerce, Routing Key: order.placed

// Override routing key
#[MessageName('user.premium.upgraded')]
#[AmqpRoutingKey('user.*.upgraded')]
// Exchange: user.premium, Routing Key: user.*.upgraded
```

See [AMQP Routing Guide](amqp-routing-guide.md) for complete documentation.

### 5. Cleanup Command
**Location:** `src/Outbox/Command/CleanupOutboxCommand.php`

Console command to clean up processed outbox messages older than a specified retention period.
- Uses DBAL for efficient deletion
- Works with standard `messenger_messages` table
- No external dependencies ✨

## Configuration

### messenger.yaml
```yaml
framework:
    messenger:
        transports:
            # Outbox - stores events in database
            outbox:
                dsn: 'doctrine://default?queue_name=outbox'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxSerializer'
                options:
                    auto_setup: false

            # AMQP - publishes to RabbitMQ
            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxSerializer'
                options:
                    auto_setup: true
                    exchange:
                        name: 'fsm.sla'
                        type: fanout
                        durable: true

        routing:
            # Route domain events to outbox
            'App\Domain\Event\YourEvent': outbox
```

### services.yaml
```yaml
services:
    # AMQP Routing Strategy
    Freyr\Messenger\Outbox\Routing\AmqpRoutingStrategyInterface:
        class: Freyr\Messenger\Outbox\Routing\DefaultAmqpRoutingStrategy
        arguments:
            $exchangeName: 'your.exchange'

    # Publishing Strategies (tagged services)
    Freyr\Messenger\Outbox\Publishing\AmqpPublishingStrategy:
        tags: ['messenger.outbox.publishing_strategy']

    # Publishing Strategy Registry
    Freyr\Messenger\Outbox\Publishing\PublishingStrategyRegistry:
        arguments:
            $strategies: !tagged_iterator 'messenger.outbox.publishing_strategy'

    # Outbox Bridge (Generic Handler)
    Freyr\Messenger\Outbox\EventBridge\OutboxToAmqpBridge:
        arguments:
            $dlqTransportName: 'dlq'  # Optional: customize DLQ transport name

    # Cleanup Command (optional maintenance tool)
    Freyr\Messenger\Outbox\Command\CleanupOutboxCommand:
        arguments:
            $connection: '@doctrine.dbal.default_connection'
            $tableName: 'messenger_outbox'
            $queueName: 'outbox'
```

## Usage

### 1. Define Domain Event
```php
use Freyr\Messenger\Outbox\MessageName;
use Freyr\Identity\Id;
use Carbon\CarbonImmutable;

#[MessageName('user.registered')]
final readonly class UserRegistered
{
    public function __construct(
        public Id $messageId,        // ✨ REQUIRED: UUID v7 for deduplication
        public Id $userId,
        public string $email,
        public CarbonImmutable $registeredAt,
    ) {}
}
```

**Important:** All domain events MUST have a public `messageId` property of type `Id` (UUID v7). This is validated by the serializer.

### 2. Dispatch Event
```php
use Freyr\Identity\Id;

$this->eventBus->dispatch(new UserRegistered(
    messageId: Id::generate(),   // ✨ Generate UUID v7
    userId: $userId,
    email: $email,
    registeredAt: CarbonImmutable::now()
));
```

The event is automatically:
1. Validated for `messageId` presence
2. Serialized by `OutboxSerializer` (includes `message_id` in JSON)
3. Stored in `messenger_messages` table (same transaction)
4. Committed with your business data

### 3. Process Outbox
```bash
# Terminal 1: Process outbox and publish via strategies
php bin/console messenger:consume outbox -vv

# Terminal 2: Monitor outbox messages
php bin/console messenger:stats

# Terminal 3: Monitor DLQ for unmatched events (optional)
php bin/console messenger:consume dlq -vv
```

The `OutboxToAmqpBridge` will:
1. Receive event from outbox
2. Find matching `PublishingStrategy` via registry
3. Publish via strategy (e.g., `AmqpPublishingStrategy`)
4. If no strategy matches → route to DLQ

### 4. Cleanup Old Messages (Optional Maintenance)
```bash
# Clean up delivered messages older than 7 days from messenger_outbox
php bin/console messenger:cleanup-outbox --days=7 --batch-size=1000
```

**Note:** This is optional housekeeping. Symfony Messenger marks messages as delivered but doesn't auto-delete them. Run this periodically to prevent table growth.

## Benefits

✅ **Transactional Guarantee:** Events saved atomically with business data
✅ **No Message Loss:** Database persistence ensures durability
✅ **Strategy-Based Publishing:** Extensible strategy pattern for multiple targets ✨
✅ **Automatic DLQ Routing:** Unmatched events go to DLQ automatically ✨
✅ **Generic Handler:** No code changes needed for new event types ✨
✅ **Message Deduplication:** UUID v7 `message_id` in every event ✨
✅ **Decoupled Publishing:** Async processing doesn't block business logic
✅ **Retry Support:** Built-in retry mechanism via Messenger

## Database Schema

The outbox uses a **dedicated table** (`messenger_outbox`) for performance isolation:

```sql
CREATE TABLE messenger_outbox (
    id BINARY(16) NOT NULL PRIMARY KEY,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL DEFAULT 'outbox',
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX idx_available_at (available_at),
    INDEX idx_delivered_at (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Why a separate table?**
- ✅ No lock contention with inbox operations
- ✅ Optimized indexes for outbox-specific queries
- ✅ Independent cleanup/archival policies
- ✅ Failed messages still go to shared `messenger_messages` table

See [Database Schema Guide](database-schema.md) for complete 3-table architecture.

## Error Handling

Failed messages are automatically moved to the `failed` transport. Review and retry:

```bash
# List failed messages
php bin/console messenger:failed:show

# Retry specific message
php bin/console messenger:failed:retry <id>

# Retry all failed messages
php bin/console messenger:failed:retry --force
```

## Best Practices

1. **Event Naming:** Use semantic names with `#[MessageName]` attribute (e.g., `order.placed`)
2. **Message ID:** Always include `public Id $messageId` in event constructor
3. **Serialization:** Only use serializable types in events (primitives, `Id`, `CarbonImmutable`, enums)
4. **Custom Strategies:** Implement `PublishingStrategyInterface` for non-AMQP targets (HTTP, SQS, etc.)
5. **Retention:** Run cleanup command regularly to prevent table growth
6. **Monitoring:** Monitor outbox queue size, processing lag, and DLQ
7. **Idempotency:** Ensure downstream consumers can handle duplicate events

## Creating Custom Publishing Strategies

```php
use Freyr\Messenger\Outbox\Publishing\PublishingStrategyInterface;

final readonly class HttpWebhookPublishingStrategy implements PublishingStrategyInterface
{
    public function supports(object $event): bool
    {
        // Only handle events implementing WebhookEvent interface
        return $event instanceof WebhookEvent;
    }

    public function publish(object $event): void
    {
        // Send HTTP POST to webhook endpoint
        $this->httpClient->post($event->getWebhookUrl(), [
            'json' => $this->serializer->encode($event),
        ]);
    }

    public function getName(): string
    {
        return 'http_webhook';
    }
}
```

Register with tag:
```yaml
services:
    App\Publishing\HttpWebhookPublishingStrategy:
        tags: ['messenger.outbox.publishing_strategy']
```

## DLQ Handling

Events with no matching strategy are automatically routed to DLQ. Review and process:

```bash
# View DLQ messages
php bin/console messenger:failed:show --transport=dlq

# Manually process or analyze
php bin/console dbal:run-sql "SELECT * FROM messenger_messages WHERE queue_name='dlq'"
```
