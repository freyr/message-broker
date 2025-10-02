# Outbox Pattern Implementation

## Overview

The Outbox Pattern ensures reliable event publishing by storing domain events in a database table (outbox) within the same transaction as business data. Events are then asynchronously published to external systems (AMQP/RabbitMQ).

## Architecture

```
Domain Event Dispatched
         ↓
Event Bus (Messenger)
         ↓
Outbox Transport (doctrine://)
         ↓
outbox_messages table (transactional)
         ↓
messenger:consume outbox
         ↓
OutboxToAmqpBridge
         ↓
AMQP Transport (RabbitMQ)
```

## Components

### 1. Outbox Serializer
**Location:** `src/Outbox/Serializer/OutboxEventSerializer.php`

- Serializes domain events to JSON with semantic event names
- Extracts event name from `#[EventName]` attribute
- Handles custom types: `Id`, `CarbonImmutable`, enums
- Used for both outbox storage and AMQP publishing

### 2. Outbox-to-AMQP Bridge
**Location:** `src/Outbox/EventBridge/OutboxToAmqpBridge.php`

- Consumes events from outbox transport
- Uses `#[AsMessageHandler(fromTransport: 'outbox')]` to process each event type
- Applies routing strategy to determine exchange and routing key
- Republishes to AMQP transport with proper stamps

### 3. AMQP Routing Strategy
**Location:** `src/Outbox/Routing/`

**Interface:** `AmqpRoutingStrategyInterface`
- `getExchange(string $eventName): string`
- `getRoutingKey(string $eventName): string`
- `getHeaders(string $eventName): array`

**Default Implementation:** `DefaultAmqpRoutingStrategy`
- Returns configured exchange name
- Empty routing key (fanout exchange)
- Event name in headers

### 4. Cleanup Command
**Location:** `src/Outbox/Command/CleanupOutboxCommand.php`

Console command to clean up processed outbox messages older than a specified retention period.

## Configuration

### messenger.yaml
```yaml
framework:
    messenger:
        transports:
            # Outbox - stores events in database
            outbox:
                dsn: 'doctrine://default?queue_name=outbox'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer'
                options:
                    auto_setup: false

            # AMQP - publishes to RabbitMQ
            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer'
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
```

## Usage

### 1. Define Domain Event
```php
use Sescom\FSM\Shared\Domain\Event\EventName;

#[EventName('user.registered')]
final readonly class UserRegistered
{
    public function __construct(
        public Id $userId,
        public string $email,
        public CarbonImmutable $registeredAt,
    ) {}
}
```

### 2. Dispatch Event
```php
$this->eventBus->dispatch(new UserRegistered(
    userId: $userId,
    email: $email,
    registeredAt: CarbonImmutable::now()
));
```

The event is automatically:
1. Serialized by `OutboxEventSerializer`
2. Stored in `outbox_messages` table (same transaction)
3. Committed with your business data

### 3. Process Outbox
```bash
# Terminal 1: Process outbox and publish to AMQP
php bin/console messenger:consume outbox -vv

# Terminal 2: Monitor outbox messages
php bin/console messenger:stats
```

### 4. Cleanup Old Messages
```bash
# Clean up messages older than 7 days
php bin/console app:cleanup-outbox --days=7
```

## Benefits

✅ **Transactional Guarantee:** Events saved atomically with business data
✅ **No Message Loss:** Database persistence ensures durability
✅ **Exactly-Once Publishing:** Each event published once to AMQP
✅ **Decoupled Publishing:** Async processing doesn't block business logic
✅ **Flexible Routing:** Strategy pattern for custom routing logic
✅ **Retry Support:** Built-in retry mechanism via Messenger

## Database Schema

The outbox uses Symfony Messenger's standard `messenger_messages` table for the outbox queue.

Additional custom table for tracking (optional):
```sql
CREATE TABLE outbox_messages (
    id BINARY(16) NOT NULL PRIMARY KEY,
    message_id BINARY(16) NOT NULL UNIQUE,
    event_name VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    attempts INT DEFAULT 0,
    last_error TEXT NULL,
    INDEX idx_status_created (status, created_at)
);
```

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

1. **Event Naming:** Use semantic names with `#[EventName]` attribute
2. **Serialization:** Only use serializable types in events (primitives, Id, Carbon, enums)
3. **Retention:** Run cleanup command regularly to prevent table growth
4. **Monitoring:** Monitor outbox queue size and processing lag
5. **Idempotency:** Ensure downstream consumers can handle duplicate events
