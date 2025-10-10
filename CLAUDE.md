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
Events are consumed from AMQP natively with deduplication using middleware-based approach:

```
AMQP Transport (native Symfony Messenger)
→ MessageNameSerializer translates 'type' header (semantic name → FQN)
→ Native Symfony Serializer deserializes body + stamps from X-Message-Stamp-* headers
→ Routes to handler (based on PHP class)
→ DeduplicationMiddleware (checks message_broker_deduplication table)
→ If duplicate: skip handler | If new: INSERT + process
→ Application Handler → Business Logic (all within transaction)
```

### Key Innovation: "Fake FQN" Pattern + Native Stamp Handling
- **Native Transport**: Uses Symfony Messenger's built-in AMQP transport (no custom commands)
- **MessageNameSerializer**: Translates semantic message names (e.g., `order.placed`) in `type` header to PHP FQN, then delegates to native Symfony Serializer
- **Native Stamp Handling**: Stamps (MessageIdStamp, MessageNameStamp) automatically serialized/deserialized via `X-Message-Stamp-*` headers by Symfony
- **DeduplicationMiddleware**: Runs AFTER `doctrine_transaction` middleware (priority -10), ensuring deduplication checks happen within the transaction
- **Atomic Guarantees**: If handler succeeds, both deduplication entry and business logic changes are committed atomically
- **Retry Safety**: If handler fails, transaction rolls back, allowing message to be retried

## Directory Structure

```
messenger/
├── src/
│   ├── Doctrine/                   # Doctrine Integration
│   │   └── Type/                   # IdType (binary UUID v7 Doctrine type)
│   ├── Inbox/                      # Inbox Pattern Implementation
│   │   └── Stamp/                  # MessageNameStamp, MessageIdStamp
│   ├── Outbox/                     # Outbox Pattern Implementation
│   │   ├── Command/                # CleanupOutboxCommand (generic, DBAL-based)
│   │   ├── EventBridge/            # OutboxToAmqpBridge (adds MessageIdStamp)
│   │   ├── Routing/                # AmqpRoutingStrategyInterface, DefaultAmqpRoutingStrategy
│   │   └── MessageName.php         # Attribute for marking messages with semantic names
│   ├── Serializer/                 # Serialization Infrastructure ✨
│   │   ├── MessageNameSerializer.php  # Unified serializer for inbox & outbox (translates semantic names)
│   │   └── Normalizer/             # Built-in normalizers (IdNormalizer, CarbonImmutableNormalizer)
│   └── DeduplicationMiddleware.php # Middleware for inbox deduplication ✨
├── docs/                           # Comprehensive architecture documentation
├── config/                         # Configuration examples (empty placeholder)
└── README.md                       # Full user guide
```

## Common Commands

### Running Outbox Worker (Publishing)
```bash
php bin/console messenger:consume outbox -vv
```

### Running Inbox Consumer (AMQP to Handlers)
**Prerequisites**: Queue must already exist in RabbitMQ with proper bindings configured.

Consume directly from AMQP transport (one worker per queue):
```bash
# Example: consume from amqp_orders transport
php bin/console messenger:consume amqp_orders -vv
```

Messages are automatically:
1. Deserialized by InboxSerializer into typed PHP objects
2. Deduplicated by DeduplicationMiddleware
3. Routed to handlers based on PHP class

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

### Database Schema - 3-Table Architecture (+ Optional Inbox Buffer)

**IMPORTANT:** The package uses a **3-table approach** for optimal performance:

1. **`messenger_outbox`** - Dedicated outbox table for publishing events
2. **`message_broker_deduplication`** - Deduplication tracking (binary UUID v7 PK)
3. **`messenger_messages`** - Standard table for failed messages (shared monitoring)

**Benefits:**
- ✅ Native AMQP transport consumption (no custom commands)
- ✅ Middleware-based deduplication (more native to Symfony Messenger)
- ✅ Transactional guarantees (deduplication + handler in same transaction)
- ✅ Optimized indexes per use case
- ✅ Independent cleanup policies
- ✅ Unified failed message monitoring
- ✅ Flexible: Direct AMQP→Handler or AMQP→Inbox→Handler

See `docs/database-schema.md` for complete migration examples.

### Messenger Configuration (messenger.yaml)
The package requires specific messenger transport configuration:

```yaml
framework:
  messenger:
    # Failure transport for handling failed messages
    failure_transport: failed

    # Middleware configuration
    # DeduplicationMiddleware runs AFTER doctrine_transaction (priority -10)
    # This ensures deduplication INSERT is within the transaction
    default_middleware:
      enabled: true
      allow_no_handlers: false

    buses:
      messenger.bus.default:
        middleware:
          - doctrine_transaction  # Priority 0 (starts transaction)
          # DeduplicationMiddleware (priority -10) registered via service tag
          # Runs after transaction starts, before handlers

    transports:
      outbox:
        dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        serializer: 'Freyr\MessageBroker\Serializer\MessageNameSerializer'
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2


      # AMQP transport - external message broker
      # For publishing (outbox) and consuming (inbox)
      amqp:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\MessageNameSerializer'
        options:
          auto_setup: false
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # Failed transport - for all failed messages
      failed:
        dsn: 'doctrine://default?queue_name=failed'
        options:
          auto_setup: false

    routing:
    # Outbox messages - route domain events to outbox transport
    # Example:
    # 'App\Domain\Event\OrderPlaced': outbox
    # 'App\Domain\Event\UserRegistered': outbox

    # Inbox messages (consumed from AMQP transports)
    # Messages are deserialized by MessageNameSerializer into typed objects
    # DeduplicationMiddleware automatically prevents duplicate processing
    # Handlers execute synchronously (no routing needed - AMQP transport handles delivery)
    # Example handlers:
    # #[AsMessageHandler]
    # class OrderPlacedHandler { public function __invoke(OrderPlaced $message) {} }
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
message_broker:
  inbox:
    # Message type mapping: message_name => PHP class
    # Used by MessageNameSerializer to translate semantic names to FQN during deserialization
    message_types:
    # Examples:
    # 'order.placed': 'App\Message\OrderPlaced'
    # 'user.registered': 'App\Message\UserRegistered'
```

```yml
services:
  _defaults:
    autowire: false
    autoconfigure: false
    public: false

  # Doctrine Integration
  Freyr\MessageBroker\Doctrine\Type\IdType:
    tags:
      - { name: 'doctrine.dbal.types', type: 'id_binary' }

  # Auto-register all Normalizers using Symfony's native tag
  # These will be automatically added to the @serializer service
  Freyr\MessageBroker\Serializer\Normalizer\:
    resource: '../src/Serializer/Normalizer/'
    tags: ['serializer.normalizer']

  # Message Name Serializer (unified for inbox & outbox)
  # - encode(): Sets semantic name in 'type' header (outbox)
  # - decode(): Translates semantic name to FQN (inbox)
  Freyr\MessageBroker\Serializer\MessageNameSerializer:
    arguments:
      $messageTypes: '%message_broker.inbox.message_types%'

  # Deduplication Middleware (inbox pattern)
  Freyr\MessageBroker\Inbox\DeduplicationMiddleware:
    arguments:
      $connection: '@doctrine.dbal.default_connection'
      $logger: '@logger'
    tags:
      - { name: 'messenger.middleware', priority: -10 }

  # AMQP Routing Strategy (default convention-based routing)
  Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface:
    class: Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy

  # Outbox Bridge (publishes outbox events to AMQP)
  # Note: Handler is auto-configured via #[AsMessageHandler(fromTransport: 'outbox')] attribute
  # Adds MessageIdStamp to envelope - stamps automatically serialized to X-Message-Stamp-* headers
  Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge:
    autoconfigure: true
    arguments:
      $eventBus: '@messenger.default_bus'
      $routingStrategy: '@Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface'
      $logger: '@logger'

  # Deduplication Store Cleanup Command (optional maintenance)
  # Removes old idempotency records from the deduplication store
  Freyr\MessageBroker\Command\DeduplicationStoreCleanup:
    arguments:
      $connection: '@doctrine.dbal.default_connection'
    tags: ['console.command']
```

## Development Guidelines

### Domain Events Must Use #[MessageName] Attribute and messageId Property ✨
```php
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\Routing\{AmqpExchange, AmqpRoutingKey};
use Freyr\Identity\Id;

#[MessageName('order.placed')]  // REQUIRED: Message name for routing
#[AmqpExchange('commerce')]     // OPTIONAL: Override default exchange
#[AmqpRoutingKey('order.test')]    // OPTIONAL: Override default routing key
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
1. **`messenger_outbox`** - Outbox-specific standard Doctrine transport
2. **`message_broker_deduplication`** - Deduplication tracking (binary(16) message_id PK)
3. **`messenger_messages`** - Standard (bigint auto-increment for failed)

**Key Points:**
- Outbox table isolated for publishing performance
- Failed messages → `messenger_messages` table (unified monitoring)
- Required Doctrine custom type: `id_binary` (provided by `Freyr\MessageBroker\Doctrine\Type\IdType`)
- Register the type in Doctrine configuration
- Deduplication is handled by **DeduplicationMiddleware** using `message_broker_deduplication` table
- Middleware runs AFTER `doctrine_transaction` → atomic commit of deduplication entry + handler changes
- **Recommended flow**: AMQP → InboxSerializer → DeduplicationMiddleware → Handler (no inbox transport needed)

**See:** `docs/database-schema.md` for complete migration examples and rationale.

### Inbox Message Handling (Typed Objects)

The inbox uses `MessageNameSerializer` to translate semantic message names to PHP classes, then Symfony's native serializer deserializes into typed PHP objects:

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
# config/packages/message_broker.yaml
message_broker:
    inbox:
        message_types:
            'order.placed': 'App\Message\OrderPlaced'
            'user.registered': 'App\Message\UserRegistered'
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
- ✅ Native Symfony serialization (uses @serializer service)
- ✅ Supports value objects (Id, CarbonImmutable, enums) via custom normalizers
- ✅ Semantic message names (language-agnostic)
- ✅ Stamps automatically handled via X-Message-Stamp-* headers
- ✅ Minimal custom code (~50 lines in MessageNameSerializer)

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
<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\EventBridge;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\MessageIdStamp;
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Outbox to AMQP Bridge.
 *
 * Adds MessageIdStamp to envelope (serialized to headers automatically by Symfony).
 * Stamps are transported via X-Message-Stamp-* headers natively.
 */
final readonly class OutboxToAmqpBridge
{
    public function __construct(
        private MessageBusInterface $eventBus,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'outbox')]
    public function __invoke(OutboxMessage $event): void
    {
        // Extract message name and ID
        $messageName = $this->extractMessageName($event);
        $messageId = $this->extractMessageId($event);

        // Get AMQP routing
        $exchange = $this->routingStrategy->getExchange($event, $messageName);
        $routingKey = $this->routingStrategy->getRoutingKey($event, $messageName);
        $headers = $this->routingStrategy->getHeaders($messageName);

        // Create envelope with stamps
        // MessageIdStamp will be automatically serialized to X-Message-Stamp-MessageIdStamp header
        $envelope = new Envelope($event, [
            new MessageIdStamp($messageId->__toString()),
            new AmqpStamp($routingKey, AMQP_NOPARAM, $headers),
            new TransportNamesStamp(['amqp']),
        ]);

        $this->logger->info('Publishing event to AMQP', [
            'message_name' => $messageName,
            'event_class' => $event::class,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);

        $this->eventBus->dispatch($envelope);
    }

    private function extractMessageName(OutboxMessage $event): string
    {
        $reflection = new \ReflectionClass($event);
        $attributes = $reflection->getAttributes(MessageName::class);

        if (empty($attributes)) {
            throw new \RuntimeException(sprintf('Event %s must have #[MessageName] attribute', $event::class));
        }

        /** @var MessageName $messageNameAttr */
        $messageNameAttr = $attributes[0]->newInstance();

        return $messageNameAttr->name;
    }

    private function extractMessageId(OutboxMessage $event): Id
    {
        $reflection = new \ReflectionClass($event);

        if (! $reflection->hasProperty('messageId')) {
            throw new \RuntimeException(sprintf(
                'Event %s must have a public messageId property of type Id',
                $event::class
            ));
        }

        $property = $reflection->getProperty('messageId');

        if (! $property->isPublic()) {
            throw new \RuntimeException(sprintf('Property messageId in event %s must be public', $event::class));
        }

        $messageId = $property->getValue($event);

        if (!$messageId instanceof Id) {
            throw new \RuntimeException(sprintf(
                'Property messageId in event %s must be of type %s, got %s',
                $event::class,
                Id::class,
                get_debug_type($messageId)
            ));
        }

        return $messageId;
    }
}

```

**Benefits:**
- No code changes needed when adding new events
- All events automatically published to AMQP
- Stamps automatically transported via native Symfony mechanism
- Convention-based routing with attribute overrides
- Failed publishing handled by Messenger's retry/failed transport

### Scaling Considerations
- Run multiple inbox workers: `messenger:consume inbox` (uses SKIP LOCKED automatically)
- Run multiple AMQP consumers: one per queue (recommended)
- Run multiple outbox workers: `messenger:consume outbox`
- All workers support horizontal scaling

## Important Implementation Details

1. **3-Table Architecture**: The package uses dedicated tables for outbox/deduplication/failed:
   - `messenger_outbox` (table_name in doctrine:// DSN for publishing)
   - `message_broker_deduplication` (deduplication tracking for consumed messages)
   - `messenger_messages` (standard for failed messages)

2. **"Fake FQN" Pattern**: The package uses a clever approach to combine semantic naming with native Symfony behavior:
   - **Publishing**: OutboxSerializer sets `type` header to semantic name (e.g., `order.placed`) instead of PHP FQN
   - **Consuming**: MessageNameSerializer translates semantic name → FQN, then delegates to native Symfony Serializer
   - **Stamps**: Automatically serialized/deserialized via `X-Message-Stamp-*` headers by Symfony
   - **Result**: External systems see semantic names, internal code uses native Symfony patterns

3. **DeduplicationMiddleware**: The middleware runs AFTER `doctrine_transaction` middleware (priority -10):
   - Checks `MessageIdStamp` and `MessageNameStamp` on incoming messages (restored automatically from headers)
   - Attempts INSERT into `message_broker_deduplication` table
   - If duplicate (UniqueConstraintViolationException): skips handler execution
   - If new: processes message normally
   - Transaction commits: deduplication entry + handler changes are atomic
   - Transaction rolls back: deduplication entry is rolled back, message can be retried

4. **AMQP Infrastructure Requirements**: Native AMQP transport consumption assumes RabbitMQ infrastructure is already configured:
   - Queues must exist
   - Exchanges must exist
   - Queue-to-exchange bindings must be configured

   Symfony Messenger AMQP transport only consumes from existing queues; it does not declare or bind queues/exchanges (unless auto_setup is enabled).

5. **AMQP Consumer ACK Behavior**: Native AMQP transport ACKs messages after successful handler execution. Messages are NACK'd if they fail validation (MessageNameSerializer) or if handlers throw exceptions.

6. **Binary UUID v7 Storage**: The `message_broker_deduplication` table uses binary(16) for message_id column (primary key). Inbox and outbox tables use binary(16) for id column. This is a hard requirement enforced by global CLAUDE.md settings.

8. **Message Format**: AMQP messages use native Symfony serialization with semantic `type` header:
   ```
   Headers:
     type: order.placed  (semantic message name)
     X-Message-Stamp-MessageIdStamp: [{"messageId":"01234567-89ab..."}]  (auto-generated)

   Body:
   {
     "messageId": "01234567-89ab-cdef-0123-456789abcdef",
     "orderId": "550e8400-e29b-41d4-a716-446655440000",
     "totalAmount": 123.45,
     "placedAt": "2025-10-08T13:30:00+00:00"
   }
   ```
   - **type header**: Semantic message name (e.g., `order.placed`) - language-agnostic
   - **X-Message-Stamp-*** headers: Symfony stamps (MessageIdStamp, etc.) - automatically handled
   - **Body**: Native Symfony serialization of the message object

   The MessageNameSerializer translates the `type` header from semantic name to PHP FQN internally.

9. **Transactional Guarantees**:
   - **Outbox**: Events are only published if the business transaction commits successfully (atomicity)
   - **Inbox**: Deduplication entry and handler changes are committed in the same transaction (atomicity)

10. **At-Least-Once Delivery**: System guarantees events are delivered at least once; consumers must be idempotent (enforced by DeduplicationMiddleware).

## Namespace Convention

All classes in this package use the `Freyr\MessageBroker` namespace:
- `Freyr\MessageBroker\Inbox\*`
- `Freyr\MessageBroker\Outbox\*`

## Documentation

Comprehensive documentation is available in the `docs/` directory:
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
