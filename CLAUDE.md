# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is the **Freyr Message Broker** package - a standalone Symfony bundle providing production-ready implementations of the **Inbox** and **Outbox** patterns for reliable event publishing and consumption with transactional guarantees and automatic deduplication.

**Key Technology Stack:**
- PHP 8.4+
- Symfony Messenger 7.3+
- Doctrine DBAL/ORM 3+
- MySQL/MariaDB with binary UUID v7 support
- RabbitMQ/AMQP (php-amqplib)
- freyr/identity package for UUID v7

## Architecture

### Outbox Pattern (Publishing Events)
Events are stored in a database table within the same transaction as business data, then asynchronously published using a **strategy-based architecture**:

```
Domain Event → Event Bus (Messenger) → Outbox Transport (doctrine://)
→ messenger_messages table (transactional) → messenger:consume outbox
→ OutboxToAmqpBridge → AMQP (with routing strategy)
```

**Key Features:**
- **Generic Handler:** Single `__invoke()` method handles all events
- **AMQP Routing Strategy:** Determines exchange, routing key, and headers
- **Message ID Validation:** Enforces `messageId` (UUID v7) in all events
- **Convention-Based Routing:** Automatic routing with attribute overrides

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
│   │   ├── Command/                # ConsumeAmqpToMessengerCommand, AmqpSetupCommand
│   │   ├── Message/                # InboxEventMessage (wrapper DTO)
│   │   ├── Serializer/             # InboxSerializer
│   │   ├── Stamp/                  # MessageNameStamp, MessageIdStamp, SourceQueueStamp
│   │   └── Transport/              # DoctrineInboxConnection, DoctrineInboxTransportFactory
│   ├── Outbox/                     # Outbox Pattern Implementation
│   │   ├── Command/                # CleanupOutboxCommand (generic, DBAL-based)
│   │   ├── EventBridge/            # OutboxToAmqpBridge (AMQP publisher)
│   │   ├── Routing/                # AmqpRoutingStrategyInterface, DefaultAmqpRoutingStrategy
│   │   ├── Serializer/             # OutboxSerializer (validates messageId)
│   │   └── MessageName.php         # Attribute for marking messages with semantic names
│   └── Serializer/                 # Serialization Infrastructure ✨
│       └── Normalizer/             # Built-in normalizers (IdNormalizer, CarbonImmutableNormalizer)
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

# Clean up old outbox messages (older than 7 days) - OPTIONAL
php bin/console messenger:cleanup-outbox --days=7 --batch-size=1000

# Note: This is optional housekeeping. Symfony marks messages as delivered but doesn't
# auto-delete them. Run periodically (cron/scheduler) to prevent messenger_outbox growth.
```

## Configuration Requirements

### Database Schema - 3-Table Architecture

**IMPORTANT:** The package uses a **3-table approach** for optimal performance:

1. **`messenger_outbox`** - Dedicated outbox table (isolated from inbox)
2. **`messenger_inbox`** - Dedicated inbox table (binary UUID PK with INSERT IGNORE)
3. **`messenger_messages`** - Standard table for failed messages (shared monitoring)

**Benefits:**
- ✅ No lock contention between inbox/outbox
- ✅ Optimized indexes per use case
- ✅ Independent cleanup policies
- ✅ Unified failed message monitoring

See `docs/database-schema.md` for complete migration examples.

### Messenger Configuration (messenger.yaml)
The package requires specific messenger transport configuration:

```yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            # Outbox - dedicated table for performance isolation
            outbox:
                dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
                serializer: 'Freyr\MessageBroker\Outbox\Serializer\OutboxSerializer'
                options:
                    auto_setup: false  # Use migrations

            # Inbox - dedicated table with custom transport
            inbox:
                dsn: 'inbox://default?table_name=messenger_inbox&queue_name=inbox'
                serializer: 'Freyr\MessageBroker\Inbox\Serializer\InboxSerializer'
                options:
                    auto_setup: false  # Use migrations

            # AMQP - external broker
            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\MessageBroker\Outbox\Serializer\OutboxSerializer'

            # Failed - standard messenger_messages table
            failed:
                dsn: 'doctrine://default?queue_name=failed'
                options:
                    auto_setup: false

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
            id_binary: Freyr\MessageBroker\Doctrine\Type\IdType
```

### Services Configuration
```yaml
parameters:
    # Message type mapping for inbox
    inbox.message_types:
        'order.placed': 'App\Message\OrderPlaced'
        'user.registered': 'App\Message\UserRegistered'

services:
    # Custom Normalizers/Denormalizers (optional - you can add your own)
    # The package auto-registers normalizers from Serializer/Normalizer/ folder
    # Applications can add their own by tagging with Symfony's native 'serializer.normalizer'

    # Example: Auto-register all your normalizers from a folder
    # App\Serializer\Normalizer\:
    #     resource: '../src/Serializer/Normalizer/'
    #     tags: ['serializer.normalizer']

    # Example: Single normalizer with custom priority
    # App\Serializer\Normalizer\MoneyNormalizer:
    #     tags:
    #         - { name: 'serializer.normalizer', priority: 10 }  # Higher priority = earlier in chain

    # Note: InboxSerializer and OutboxSerializer use Symfony's @serializer service
    # which automatically collects all normalizers tagged with 'serializer.normalizer'

    # Inbox Transport Factory (REQUIRED - registers inbox:// DSN)
    Freyr\MessageBroker\Inbox\Transport\DoctrineInboxTransportFactory:
        arguments:
            $connection: '@doctrine.dbal.default_connection'
        tags: ['messenger.transport_factory']

    # AMQP Routing Strategy (Outbox) ✨
    # Uses convention-based routing: first 2 parts of message name → exchange
    # Supports #[AmqpExchange] and #[AmqpRoutingKey] attribute overrides
    Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface:
        class: Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy

    # Outbox Bridge (AMQP Publisher) ✨
    Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge:
        arguments:
            $eventBus: '@messenger.default_bus'
            $routingStrategy: '@Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface'
            $serializer: '@Freyr\MessageBroker\Outbox\Serializer\OutboxSerializer'
            $logger: '@logger'

    # Cleanup Command (optional maintenance) ✨
    Freyr\MessageBroker\Outbox\Command\CleanupOutboxCommand:
        arguments:
            $connection: '@doctrine.dbal.default_connection'
            $tableName: 'messenger_outbox'  # Match transport table_name
            $queueName: 'outbox'
```

## Development Guidelines

### Domain Events Must Use #[MessageName] Attribute and messageId Property ✨
```php
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\Routing\{AmqpExchange, AmqpRoutingKey};
use Freyr\Identity\Id;

#[MessageName('order.placed')]  // REQUIRED: Message name for routing
#[AmqpExchange('commerce')]     // OPTIONAL: Override default exchange
#[AmqpRoutingKey('order.*')]    // OPTIONAL: Override default routing key
final readonly class OrderPlaced
{
    public function __construct(
        public Id $messageId,        // ✨ REQUIRED: UUID v7 for correlation
        public Id $orderId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

**Critical Requirements:**
1. Every outbox event MUST have `#[MessageName('domain.subdomain.action')]` attribute
2. Every outbox event MUST have public `messageId` property of type `Id` (UUID v7)
3. The serializer validates both requirements and throws exceptions if missing

**AMQP Routing (Optional):**
4. Use `#[AmqpExchange('name')]` to override default exchange (first 2 parts of message name)
5. Use `#[AmqpRoutingKey('key')]` to override default routing key (full message name)

See `docs/amqp-routing-guide.md` for complete routing documentation.

### Database Schema Requirements ✨ **3-TABLE ARCHITECTURE**

**Tables:**
1. **`messenger_outbox`** - Outbox-specific (binary(16) id, standard Doctrine transport)
2. **`messenger_inbox`** - Inbox-specific (binary(16) id from message_id, INSERT IGNORE deduplication)
3. **`messenger_messages`** - Standard (bigint auto-increment for failed)

**Key Points:**
- Inbox and outbox use **separate tables** for performance isolation
- Failed messages from both → `messenger_messages` table (unified monitoring)
- Required Doctrine custom type: `id_binary` (provided by `Freyr\MessageBroker\Doctrine\Type\IdType`)
- Register the type in Doctrine configuration
- Deduplication is handled at database level via primary key constraint with INSERT IGNORE

**See:** `docs/database-schema.md` for complete migration examples and rationale.

### Inbox Message Handling (Typed Objects)

The inbox uses `InboxSerializer` to automatically deserialize JSON payloads into typed PHP objects:

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
    Freyr\MessageBroker\Inbox\Serializer\InboxSerializer:
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

### Custom Serialization with Normalizers/Denormalizers ✨

The package uses **Symfony Serializer** with custom normalizers/denormalizers for type handling. This allows applications to add their own serialization logic for custom types.

**Package-Provided Normalizers:**
- `IdNormalizer` - For `Freyr\Identity\Id` (UUID v7) - implements both NormalizerInterface and DenormalizerInterface
- `CarbonImmutableNormalizer` - For `Carbon\CarbonImmutable` - implements both NormalizerInterface and DenormalizerInterface

**Adding Custom Normalizers:**

1. **Create a Normalizer (implements both interfaces):**
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

2. **Auto-register all normalizers from a folder:**
```yaml
services:
    App\Serializer\Normalizer\:
        resource: '../src/Serializer/Normalizer/'
        tags: ['serializer.normalizer']
```

3. **Control normalizer order with priority (optional):**
```yaml
services:
    # Higher priority = earlier in the normalizer chain
    App\Serializer\Normalizer\MoneyNormalizer:
        tags:
            - { name: 'serializer.normalizer', priority: 10 }

    # Lower priority = later in the normalizer chain
    App\Serializer\Normalizer\GenericValueObjectNormalizer:
        tags:
            - { name: 'serializer.normalizer', priority: -50 }
```

**How It Works:**
- Both serializers (`InboxSerializer` and `OutboxSerializer`) use Symfony's native `@serializer` service
- Symfony automatically collects all normalizers tagged with `serializer.normalizer`
- Normalizers are ordered by priority (higher priority = earlier in chain, default = 0)
- Symfony's built-in `ObjectNormalizer` has low priority (always last as a fallback)
- The serializer uses the appropriate normalizer based on type detection

**Benefits:**
- ✅ Uses Symfony's native serializer service - standard and well-tested
- ✅ Applications can add serialization for their own types
- ✅ No manual enumeration needed - normalizers are auto-discovered via tagged iterator
- ✅ Priority system allows fine-grained control over normalization order
- ✅ Extensible architecture - add normalizers by simply tagging them with `serializer.normalizer`
- ✅ Follows Symfony best practices
- ✅ Supports complex nested objects
- ✅ Normalizers work with any Symfony component that uses the serializer service

### Outbox Bridge Pattern ✨ **SIMPLIFIED**
The `OutboxToAmqpBridge` is a **generic handler** that publishes all outbox events to AMQP:

```php
// ✅ Single generic handler for ALL events
#[AsMessageHandler(fromTransport: 'outbox')]
public function __invoke(object $event): void {
    // Get AMQP routing (exchange, routing key, headers)
    $routing = $this->routingStrategy->getRoutingFor($event);

    // Publish to AMQP
    $this->eventBus->dispatch(new Envelope($event, [new AmqpStamp(...)]));
}
```

**Benefits:**
- No code changes needed when adding new events
- All events automatically published to AMQP
- Convention-based routing with attribute overrides
- Failed publishing handled by Messenger's retry/failed transport

### Scaling Considerations
- Run multiple inbox workers: `messenger:consume inbox` (uses SKIP LOCKED automatically)
- Run multiple AMQP consumers: one per queue (recommended)
- Run multiple outbox workers: `messenger:consume outbox`
- All workers support horizontal scaling

## Important Implementation Details

1. **3-Table Architecture**: The package uses dedicated tables for inbox/outbox:
   - `messenger_outbox` (table_name in doctrine:// DSN)
   - `messenger_inbox` (table_name in inbox:// DSN)
   - `messenger_messages` (standard for failed)

   This prevents lock contention and allows independent scaling/cleanup.

2. **AMQP Infrastructure Requirements**: The `inbox:ingest` command assumes RabbitMQ infrastructure is already configured:
   - Queues must exist
   - Exchanges must exist
   - Queue-to-exchange bindings must be configured

   The command only consumes from existing queues; it does not declare or bind queues/exchanges.

3. **AMQP Consumer ACK Behavior**: The `ConsumeAmqpToMessengerCommand` ACKs messages after successful dispatch to the inbox transport. Messages are NACK'd if they fail validation (missing required fields) or encounter errors during processing.

4. **Binary UUID v7 Storage**: Inbox and outbox tables use binary(16) for id column. The inbox id comes from `message_id` header (external), outbox id is generated internally. This is a hard requirement enforced by global CLAUDE.md settings.

5. **Cleanup Command**: `CleanupOutboxCommand` is **optional** - Symfony Messenger marks messages as `delivered_at` but doesn't auto-delete. Run periodically to prevent table growth. Failed messages in `messenger_messages` are managed by Symfony's `messenger:failed:*` commands.

6. **Message Format**: AMQP messages must follow this strict JSON format (all fields required):
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

7. **Transactional Guarantees**: Outbox ensures events are only published if the business transaction commits successfully (atomicity).

8. **At-Least-Once Delivery**: System guarantees events are delivered at least once; consumers must be idempotent.

9. **Custom Transport Factory**: The `DoctrineInboxTransportFactory` must be registered as a Messenger transport factory to enable the `inbox://` DSN scheme. It supports `table_name` parameter for configurable table names.

## Namespace Convention

All classes in this package use the `Freyr\MessageBroker` namespace:
- `Freyr\MessageBroker\Inbox\*`
- `Freyr\MessageBroker\Outbox\*`

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
