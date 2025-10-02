# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is the **Freyr Messenger** package - a standalone Symfony bundle providing production-ready implementations of the **Inbox** and **Outbox** patterns for reliable event publishing and consumption with transactional guarantees and automatic deduplication.

**Key Technology Stack:**
- PHP 8.4+
- Symfony Messenger 7.3+
- Doctrine DBAL/ORM 3+
- MySQL/MariaDB with binary UUID v7 support
- RabbitMQ/AMQP (php-amqplib)
- freyr/identity package for UUID v7

## Architecture

### Outbox Pattern (Publishing Events)
Events are stored in a database table within the same transaction as business data, then asynchronously published to AMQP/RabbitMQ:

```
Domain Event → Event Bus (Messenger) → Outbox Transport (doctrine://)
→ messenger_messages table (transactional) → messenger:consume outbox
→ OutboxToAmqpBridge → AMQP Transport (RabbitMQ)
```

### Inbox Pattern (Consuming Events)
Events are consumed from AMQP with deduplication using binary UUID v7 as primary key:

```
AMQP (RabbitMQ) → ConsumeAmqpToMessengerCommand (php-amqplib)
→ Extract message_id → Create InboxEventMessage → Messenger Inbox Transport (inbox://)
→ INSERT IGNORE with id as PK (automatic deduplication)
→ messenger:consume inbox (SKIP LOCKED) → InboxEventMessageHandler
→ EventHandlerRegistry → Domain Event Handlers
```

### Key Innovation: Custom Doctrine Transport with INSERT IGNORE
The `DoctrineInboxConnection` extends Symfony's `DoctrineTransport` to use binary UUID v7 (from `message_id` header) as primary key with `INSERT IGNORE` for database-level deduplication.

## Directory Structure

```
messenger/
├── src/
│   ├── Doctrine/                   # Doctrine Integration
│   │   └── Type/                   # IdType (binary UUID v7 Doctrine type)
│   ├── Inbox/                      # Inbox Pattern Implementation
│   │   ├── Command/                # ConsumeAmqpToMessengerCommand
│   │   ├── Message/                # InboxEventMessage (wrapper DTO)
│   │   ├── Serializer/             # TypedInboxSerializer
│   │   ├── Stamp/                  # MessageNameStamp, MessageIdStamp, SourceQueueStamp
│   │   └── Transport/              # DoctrineInboxConnection, DoctrineInboxTransportFactory
│   └── Outbox/                     # Outbox Pattern Implementation
│       ├── Command/                # CleanupOutboxCommand
│       ├── EventBridge/            # OutboxToAmqpBridge (outbox → AMQP)
│       ├── Routing/                # AmqpRoutingStrategyInterface, DefaultAmqpRoutingStrategy
│       ├── Serializer/             # OutboxEventSerializer
│       └── MessageName.php         # Attribute for marking messages with semantic names
├── docs/                           # Comprehensive architecture documentation
├── config/                         # Configuration examples (empty placeholder)
└── README.md                       # Full user guide
```

## Common Commands

### Running Outbox Worker (Publishing)
```bash
php bin/console messenger:consume outbox -vv
```

### Running Inbox Consumer (AMQP to Messenger)
**Prerequisites**: Queue must already exist in RabbitMQ with proper bindings configured.

```bash
php bin/console inbox:ingest --queue=your.queue
```

### Running Inbox Worker (Processing)
```bash
php bin/console messenger:consume inbox -vv
```

### Testing Deduplication
```bash
# Send 3 identical messages
php bin/console fsm:test-inbox-dedup

# Check database - should have only 1 row
php bin/console dbal:run-sql "SELECT HEX(id), queue_name FROM messenger_messages WHERE queue_name='inbox'"
```

### Monitoring & Maintenance
```bash
# View queue statistics
php bin/console messenger:stats

# View failed messages
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry

# Clean up old outbox messages (older than 7 days)
php bin/console app:cleanup-outbox --days=7
```

## Configuration Requirements

### Messenger Configuration (messenger.yaml)
The package requires specific messenger transport configuration. The inbox uses a custom DSN scheme `inbox://`:

```yaml
framework:
    messenger:
        transports:
            outbox:
                dsn: 'doctrine://default?queue_name=outbox'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer'

            inbox:
                dsn: 'inbox://default?queue_name=inbox'
                serializer: 'Freyr\Messenger\Inbox\Serializer\TypedInboxSerializer'
                options:
                    auto_setup: true

            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer'

        routing:
            # Outbox messages
            'App\Domain\Event\YourEvent': outbox

            # Inbox messages (route by PHP class)
            'App\Message\OrderPlaced': inbox
            'App\Message\UserRegistered': inbox
```

### Doctrine Configuration
Register the custom UUID type in `config/packages/doctrine.yaml`:
```yaml
doctrine:
    dbal:
        types:
            id_binary: Freyr\Messenger\Doctrine\Type\IdType
```

### Services Configuration
```yaml
parameters:
    # Message type mapping for inbox
    inbox.message_types:
        'order.placed': 'App\Message\OrderPlaced'
        'user.registered': 'App\Message\UserRegistered'

services:
    # Typed Inbox Serializer
    Freyr\Messenger\Inbox\Serializer\TypedInboxSerializer:
        arguments:
            $messageTypes: '%inbox.message_types%'

    # Inbox Transport Factory (REQUIRED - registers inbox:// DSN)
    Freyr\Messenger\Inbox\Transport\DoctrineInboxTransportFactory:
        arguments:
            $connection: '@doctrine.dbal.default_connection'
        tags: ['messenger.transport_factory']

    # AMQP Routing Strategy (Outbox)
    Freyr\Messenger\Outbox\Routing\AmqpRoutingStrategyInterface:
        class: Freyr\Messenger\Outbox\Routing\DefaultAmqpRoutingStrategy
        arguments:
            $exchangeName: 'your.exchange'
```

## Development Guidelines

### Domain Events Must Use #[MessageName] Attribute
```php
use Freyr\Messenger\Outbox\MessageName;

#[MessageName('order.placed')]
final readonly class OrderPlaced
{
    public function __construct(
        public Id $orderId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

### Database Schema Requirements
- The `messenger_messages` table uses **binary(16)** for the `id` column (UUID v7) instead of bigint auto-increment
- Required Doctrine custom type: `id_binary` (provided by `Freyr\Messenger\Doctrine\Type\IdType`)
- Register the type in Doctrine configuration: `Type::addType('id_binary', IdType::class)`
- Deduplication is handled at database level via primary key constraint with INSERT IGNORE

### Inbox Message Handling (Typed Objects)

The inbox uses `TypedInboxSerializer` to automatically deserialize JSON payloads into typed PHP objects:

**1. Define Message Class**
```php
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

**2. Configure Message Type Mapping**
```yaml
# config/services.yaml
parameters:
    inbox.message_types:
        'order.placed': 'App\Message\OrderPlaced'

services:
    Freyr\Messenger\Inbox\Serializer\TypedInboxSerializer:
        arguments:
            $messageTypes: '%inbox.message_types%'
```

**3. Use Standard Messenger Handlers**
```php
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OrderPlacedHandler
{
    public function __invoke(OrderPlaced $message): void
    {
        // Type-safe access with IDE autocomplete!
        $orderId = $message->orderId;
        $amount = $message->totalAmount;
        // Process...
    }
}
```

**Benefits:**
- ✅ Type safety and IDE support
- ✅ Automatic hydration from JSON
- ✅ Supports value objects (Id, CarbonImmutable, enums)
- ✅ Fallback to stdClass for unmapped messages

See `docs/inbox-typed-messages.md` for complete guide.

### Outbox Bridge Pattern
The `OutboxToAmqpBridge` requires explicit handler methods for each domain event type with `#[AsMessageHandler(fromTransport: 'outbox')]` attribute. When adding new domain events to be published via AMQP, you must add corresponding handler methods to the bridge.

### Scaling Considerations
- Run multiple inbox workers: `messenger:consume inbox` (uses SKIP LOCKED automatically)
- Run multiple AMQP consumers: one per queue (recommended)
- Run multiple outbox workers: `messenger:consume outbox`
- All workers support horizontal scaling

## Important Implementation Details

1. **AMQP Infrastructure Requirements**: The `inbox:ingest` command assumes RabbitMQ infrastructure is already configured:
   - Queues must exist
   - Exchanges must exist
   - Queue-to-exchange bindings must be configured

   The command only consumes from existing queues; it does not declare or bind queues/exchanges.

2. **AMQP Consumer ACK Behavior**: The `ConsumeAmqpToMessengerCommand` ACKs messages after successful dispatch to the inbox transport. Messages are NACK'd if they fail validation (missing required fields) or encounter errors during processing.

3. **Binary UUID v7 Storage**: All primary keys use binary(16) format for efficient storage and chronological ordering. This is a hard requirement enforced by global CLAUDE.md settings.

4. **Message Format**: AMQP messages must follow this strict JSON format (all fields required):
   ```json
   {
     "message_name": "order.placed",
     "message_id": "01234567-89ab-cdef-0123-456789abcdef",
     "payload": { ... }
   }
   ```
   - `message_name`: Semantic message name matching the `#[MessageName]` attribute format
   - `message_id`: Unique identifier for deduplication (binary UUID v7 recommended)
   - `payload`: Event data as object

   Messages missing any required field will be rejected (NACK'd).

5. **Transactional Guarantees**: Outbox ensures events are only published if the business transaction commits successfully (atomicity).

6. **At-Least-Once Delivery**: System guarantees events are delivered at least once; consumers must be idempotent.

7. **Custom Transport Factory**: The `DoctrineInboxTransportFactory` must be registered as a Messenger transport factory to enable the `inbox://` DSN scheme.

## Namespace Convention

All classes in this package use the `Freyr\Messenger` namespace:
- `Freyr\Messenger\Inbox\*`
- `Freyr\Messenger\Outbox\*`

## Documentation

Comprehensive documentation is available in the `docs/` directory:
- `architecture.md` - High-level architecture and design principles
- `inbox-implementation.md` - Inbox pattern implementation details
- `inbox-design.md` - Inbox pattern design decisions
- `outbox-pattern.md` - Outbox pattern implementation guide

The main `README.md` provides a complete user guide with examples.

## Monitoring in Production

Deploy workers using systemd, supervisor, or Docker with:
- Time limits (e.g., `--time-limit=3600`)
- Automatic restart on failure
- Multiple replicas for high availability

Track metrics:
- Outbox queue depth (`messenger:stats`)
- Inbox processing lag
- Failed message count
- Worker health/uptime
