# Freyr Message Broker

## Inbox & Outbox Patterns for Symfony Messenger

**IMPORTANT** This library is in a very early stage of development! Not production ready!

A Symfony bundle providing reliable event publishing and consumption with transactional guarantees, automatic deduplication, and a transport-agnostic architecture.

## Features

- ✅ **Transactional Outbox** - Publish events reliably within your business transactions
- ✅ **Automatic Deduplication at the Inbox** - Binary UUID v7 primary key prevents duplicate processing
- ✅ **Typed Message Handlers** - Type-safe event consumption with IDE autocomplete
- ✅ **Horizontal Scaling** - Multiple workers with database-level SKIP LOCKED
- ✅ **Transport-Agnostic Core** - Pluggable publisher architecture (AMQP included, SQS/Kafka planned)
- ✅ **AMQP Topology Management** - Declare exchanges, queues, and bindings from YAML configuration

## Restrictions
- **Zero Configuration** - (in progress) Symfony Flex recipe automates installation

## Quick Start

### Installation

```bash
composer require freyr/message-broker
```
** Flex is not registered yet **
**That's it!** Symfony Flex automatically:
- ✅ Registers the bundle
- ✅ Creates configuration files
- ✅ Adds database migrations
- ✅ Sets up environment variables

### Setup Database

The package uses a **3-table architecture** with mixed management:

**Auto-Managed by Symfony (no manual setup needed):**
1. **`messenger_outbox`** - Dedicated outbox table for publishing events
2. **`messenger_messages`** - Standard table for failed messages

**Application-Managed (manual setup required):**
3. **`message_broker_deduplication`** - Deduplication tracking (binary UUID v7 PK)

**Setup:**

```bash
# Run migrations to create deduplication table (messenger tables auto-created)
php bin/console doctrine:migrations:migrate
```

**First-Run Note:** The `messenger_outbox` and `messenger_messages` tables will be automatically created when you first start the worker. This is expected behaviour with `auto_setup: true`.

See `docs/database-schema.md` for complete schema details and rationale.

## How it works

1. You emit your event (e.g., `inventory.stock.received`) from Application A (Inventory management)

2. Messenger routing directs it to the **outbox** transport (Doctrine/database), inserting it in the same transaction as your business logic

3. `php bin/console messenger:consume outbox -vv` fetches events from outbox and publishes them to AMQP
   - Default routing: exchange = `inventory.stock`, routing key = `inventory.stock.received`
   - Transactional outbox provides **at-least-once delivery** (events may be sent multiple times)
   - Deduplication must happen at the receiving end

4. Application B (Procurement) sets up AMQP infrastructure:
   - Queue: `inventory_stock`
   - Binds to exchange: `inventory.stock`
   - Binding key: `inventory.stock.*`

5. `php bin/console messenger:consume amqp_orders -vv` fetches events from AMQP and passes them to messenger worker
   - Transactional middleware starts transaction
   - Deduplication middleware checks for duplicated event_id
     - Skips event when id exists
     - Inserts event_id if not
   - Command handler executes business logic, performs database operation
   - Transaction is committed, ack is sent to AMQP
   - If ack fails, event can be redelivered, but deduplication middleware will skip it

### Start Workers

```bash
# Process outbox (publish events to AMQP)
php bin/console messenger:consume outbox -vv

# Consume messages from AMQP (example: orders queue)
php bin/console messenger:consume amqp_orders -vv
```

## Usage

## Publishing Events via Outbox

### Step 1: Implement OutboxEventInterface

All events published through the outbox pattern must implement `OutboxEventInterface`:

  ```php
  use Freyr\MessageBroker\Outbox\MessageName;
  use Freyr\MessageBroker\Outbox\OutboxMessage;

  #[MessageName('order.placed')]
  final class OrderPlaced implements OutboxMessage
  {
      public function __construct(
          public string $orderId,
          public float $amount,
      ) {
      }
  }
```

  Requirements:
  - Must have #[MessageName('semantic.name')] attribute
  - Must implement OutboxMessage marker interface

  Domain Layer Alternative

  To avoid coupling your domain to infrastructure, extend the interface:
```php
  // Your domain layer
  namespace App\Shared\Integration;

  use Freyr\MessageBroker\Outbox\OutboxEventInterface;

  interface IntegrationEvent extends OutboxEventInterface
  {
      // Your domain-specific contracts
  }

  // Your events
  #[MessageName('order.placed')]
  final class OrderPlaced implements IntegrationEvent
  {
      // ...
  }
```

This keeps your events referencing your own interface, not the infrastructure one.

**AMQP Routing:**

By default, events are published using convention-based routing:
- **Exchange**: First 2 parts of message name (`order.placed` → `order.placed`)
- **Routing Key**: Full message name (`order.placed`)

You can override this with YAML configuration (preferred) or attributes:

**YAML overrides** (in `config/packages/message_broker.yaml`):
```yaml
message_broker:
    amqp:
        routing:
            'order.placed':
                sender: commerce              # Publish via 'commerce' transport
                routing_key: commerce.orders   # Custom routing key
```

**Attribute overrides** (on event class):
```php
use Freyr\MessageBroker\Amqp\Routing\AmqpExchange;
use Freyr\MessageBroker\Amqp\Routing\AmqpRoutingKey;

#[MessageName('order.placed')]
#[AmqpExchange('commerce')]                  // Custom exchange
#[AmqpRoutingKey('commerce.order.placed')]   // Custom routing key
final readonly class OrderPlaced
{
    // ...
}
```

YAML overrides take precedence over attributes.

See [AMQP Routing](docs/amqp-routing.md) for complete documentation.

#### 2. Configure Routing

Edit `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        routing:
            'App\Domain\Event\OrderPlaced': outbox
```

#### 3. Dispatch the Event

```php
use Symfony\Component\Messenger\MessageBusInterface;
use Freyr\Identity\Id;

class OrderService
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {}

    public function placeOrder(Order $order): void
    {
        // Save order (transaction started)
        $this->entityManager->persist($order);

        // Dispatch event (saved to outbox in same transaction)
        // Note: messageId is auto-generated by MessageIdStampMiddleware at dispatch
        $this->messageBus->dispatch(new OrderPlaced(
            orderId: $order->getId(),
            customerId: $order->getCustomerId(),
            totalAmount: $order->getTotalAmount(),
            placedAt: CarbonImmutable::now()
        ));

        // Commit (order + event saved atomically)
        $this->entityManager->flush();
    }
}
```

The event is now stored in the outbox. Workers will publish it to AMQP asynchronously.

### Consuming Events (Inbox Pattern)

#### 1. Define Consumer Message

Create a message class matching the event structure:

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

**Important:** Consumer messages contain only business data (no messageId - it's transport metadata).

#### 2. Configure Message Type Mapping

Edit `config/packages/message_broker.yaml`:

```yaml
message_broker:
    inbox:
        message_types:
            'order.placed': 'App\Message\OrderPlaced'
```

#### 3. Create Handler

```php
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OrderPlacedHandler
{
    public function __invoke(OrderPlaced $message): void
    {
        // Type-safe with IDE autocomplete!
        $orderId = $message->orderId;
        $amount = $message->totalAmount;

        // Process the event...
    }
}
```

#### 4. Consume from AMQP

**Prerequisites:** RabbitMQ queue must already exist with proper bindings.

```bash
# Consume directly from AMQP transport
php bin/console messenger:consume amqp_orders -vv
```

Messages are automatically:
1. Deserialised by InboxSerialiser into typed PHP objects
2. Deduplicated by DeduplicationMiddleware
3. Routed to handlers based on PHP class

### AMQP Topology Setup

Declare RabbitMQ exchanges, queues, and bindings from a YAML configuration checked into your codebase.

#### Configuration

Add topology under `message_broker.amqp.topology` in `config/packages/message_broker.yaml`:

```yaml
message_broker:
    inbox:
        message_types:
            'order.placed': 'App\Message\OrderPlaced'
    amqp:
        topology:
            exchanges:
                commerce:
                    type: topic          # required: direct, fanout, topic, headers
                    durable: true        # default: true
                dlx:
                    type: direct

            queues:
                orders_queue:
                    durable: true        # default: true
                    arguments:
                        x-dead-letter-exchange: dlx
                        x-dead-letter-routing-key: dlq.orders
                        x-queue-type: quorum
                        x-delivery-limit: 5
                dlq.orders: {}

            bindings:
                - exchange: commerce
                  queue: orders_queue
                  binding_key: 'order.*'
                - exchange: dlx
                  queue: dlq.orders
                  binding_key: 'dlq.orders'
```

Exchanges are declared in configuration order. Integer queue arguments (`x-message-ttl`, `x-max-length`, `x-delivery-limit`, etc.) are normalised to integers automatically.

#### Command Usage

```bash
# Declare topology against live RabbitMQ
php bin/console message-broker:setup-amqp --dsn=amqp://guest:guest@localhost:5672/%2f

# Preview planned actions without connecting
php bin/console message-broker:setup-amqp --dry-run

# Export RabbitMQ definitions JSON to stdout
php bin/console message-broker:setup-amqp --dump

# Export definitions to file (importable via rabbitmqctl)
php bin/console message-broker:setup-amqp --dump --output=definitions.json

# Override vhost in exported definitions
php bin/console message-broker:setup-amqp --dump --vhost=/production
```

The `--dump` output follows the [RabbitMQ definitions format](https://www.rabbitmq.com/docs/definitions) and can be imported with:

```bash
rabbitmqctl import_definitions definitions.json
```

The command is idempotent — running it multiple times produces the same result. If the DSN is not provided via `--dsn`, it falls back to the `MESSENGER_AMQP_DSN` environment variable.

### Message Format

AMQP messages use this structure:

**Headers:**
- `type: order.placed` - Semantic message name
- `X-Message-Stamp-MessageIdStamp: [{"messageId":"..."}]` - For deduplication

**Body (messageId stripped):**
```json
{
  "orderId": "550e8400-e29b-41d4-a716-446655440000",
  "customerId": "01234567-89ab-cdef-0123-456789abcdef",
  "totalAmount": 99.99,
  "placedAt": "2024-01-01T12:00:00+00:00"
}
```

**Note:** `messageId` is transport metadata (for deduplication), not business data. It's sent via MessageIdStamp header, not in the payload.

## Configuration

### After Installation

Symfony Flex creates these configuration files:

**config/packages/message_broker.yaml:**
```yaml
message_broker:
    inbox:
        message_types: {}  # Add your mappings here
```

**config/packages/messenger.yaml:**
```yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            # Outbox transport - AUTO-MANAGED (auto_setup: true)
            outbox:
                dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
                serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
                options:
                    auto_setup: true  # Symfony creates table automatically

            # AMQP publish transport - MANUAL MANAGEMENT (auto_setup: false)
            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
                options:
                    auto_setup: false  # Infrastructure managed by ops

            # AMQP consumption transport - MANUAL MANAGEMENT (auto_setup: false)
            amqp_orders:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'
                options:
                    auto_setup: false  # Infrastructure managed by ops
                    queues:
                        orders_queue: ~

            # Failed transport - AUTO-MANAGED (auto_setup: true)
            failed:
                dsn: 'doctrine://default?queue_name=failed'
                options:
                    auto_setup: true  # Symfony creates table automatically

        routing:
            # Add your domain events here:
            # 'App\Domain\Event\OrderPlaced': outbox
```

See CLAUDE.md for complete configuration documentation.

**.env:**
```env
MESSENGER_AMQP_DSN=amqp://guest:guest@localhost:5672/%2f
```

### Customisation

You can customise:
- Transport DSNs in `messenger.yaml`
- AMQP connection in `.env`
- Failed transport name

## Production Deployment

### Docker Compose

```yaml
services:
  # Process outbox database and publish to AMQP
  worker-outbox:
    image: your-app:latest
    command: php bin/console messenger:consume outbox --time-limit=3600
    restart: always
    deploy:
      replicas: 2

  # Consume from AMQP and process with handlers
  worker-amqp-orders:
    image: your-app:latest
    command: php bin/console messenger:consume amqp_orders --time-limit=3600
    restart: always
    deploy:
      replicas: 3
```

### Monitoring

```bash
# View queue statistics
php bin/console messenger:stats

# View failed messages
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry
```

## Documentation

**Core Architecture:**
- [Database Schema](docs/database-schema.md) - Complete 3-table architecture, migrations, and cleanup strategies
- [Outbox Pattern](docs/outbox-pattern.md) - Transactional consistency for event publishing
- [Inbox Deduplication](docs/inbox-deduplication.md) - Preventing duplicate message processing
- [Message Serialisation](docs/message-serialization.md) - Semantic naming and cross-language compatibility
- [AMQP Routing](docs/amqp-routing.md) - Convention-based routing with attribute overrides

**Developer Guide:**
- See `CLAUDE.md` for complete configuration examples and implementation details

## Manual Installation (Without Symfony Flex)

If Symfony Flex is not available in your project, follow these manual installation steps:

### 1. Install via Composer

```bash
composer require freyr/message-broker --no-scripts
```

### 2. Register the Bundle

Edit `config/bundles.php`:

```php
return [
    // ... other bundles
    Freyr\MessageBroker\FreyrMessageBrokerBundle::class => ['all' => true],
];
```

### 3. Create Configuration Files

**config/packages/message_broker.yaml:**

```yaml
message_broker:
  inbox:
    # Message type mapping: message_name => PHP class
    # Used by InboxSerializer to translate semantic names to FQN during deserialization
    message_types:
    # Examples:
    # 'order.placed': 'App\Message\OrderPlaced'
    # 'user.registered': 'App\Message\UserRegistered'

```

**config/packages/messenger.yaml:**

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
          - 'Freyr\MessageBroker\Outbox\MessageIdStampMiddleware'  # Stamps OutboxMessage at dispatch
          - doctrine_transaction  # Priority 0 (starts transaction)
          - 'Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware'  # Delegates to transport publishers
          - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'  # Inbox deduplication (priority -10)

    transports:
      outbox:
        dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2


      # AMQP transport - external message broker
      # For publishing from outbox: uses OutboxSerializer
      # For consuming to inbox: uses InboxSerializer
      amqp:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
        options:
          auto_setup: false
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # AMQP consumption transport (example) - uses InboxSerializer
      amqp_orders:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'
        options:
          auto_setup: false
          queue:
            name: 'orders_queue'
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
    # Messages are deserialized by InboxSerializer into typed objects
    # DeduplicationMiddleware automatically prevents duplicate processing
    # Handlers execute synchronously (no routing needed - AMQP transport handles delivery)
    # Example handlers:
    # #[AsMessageHandler]
    # class OrderPlacedHandler { public function __invoke(OrderPlaced $message) {} }
```

**config/packages/doctrine.yaml:**

```yaml
doctrine:
    dbal:
        types:
            id_binary: Freyr\MessageBroker\Doctrine\Type\IdType
```

**config/services.yaml:**

```yaml
services:
  # Doctrine Integration
  Freyr\MessageBroker\Doctrine\Type\IdType:
    tags:
      - { name: 'doctrine.dbal.types', type: 'id_binary' }

  # Auto-register all Normalizers using Symfony's native tag
  # These will be automatically added to the @serializer service
  Freyr\MessageBroker\Serializer\Normalizer\:
    resource: '../vendor/freyr/message-broker/src/Serializer/Normalizer/'
    tags: ['serializer.normalizer']

  # Custom ObjectNormalizer with property promotion support
  # This overrides Symfony's default ObjectNormalizer with propertyTypeExtractor
  # Lower priority (-1000) ensures it runs as fallback after specialized normalizers
  Freyr\MessageBroker\Serializer\Normalizer\PropertyPromotionObjectNormalizer:
    autowire: true
    class: Symfony\Component\Serializer\Normalizer\ObjectNormalizer
    arguments:
      $propertyTypeExtractor: '@property_info'
    tags:
      - { name: 'serializer.normalizer', priority: -1000 }

  # Inbox Serializer - for AMQP consumption
  # Injects native @serializer service with all registered normalizers
  Freyr\MessageBroker\Serializer\InboxSerializer:
    arguments:
      $messageTypes: '%message_broker.inbox.message_types%'
      $serializer: '@serializer'

  # Outbox Serializer - for AMQP publishing
  # Injects native @serializer service with all registered normalizers
  Freyr\MessageBroker\Serializer\OutboxSerializer:
    arguments:
      $serializer: '@serializer'

  # Deduplication Store (DBAL implementation)
  Freyr\MessageBroker\Inbox\DeduplicationStore:
    class: Freyr\MessageBroker\Inbox\DeduplicationDbalStore
    arguments:
      $connection: '@doctrine.dbal.default_connection'
      $logger: '@logger'

  # Deduplication Middleware (inbox pattern)
  # Runs AFTER doctrine_transaction middleware (priority -10)
  Freyr\MessageBroker\Inbox\DeduplicationMiddleware:
    arguments:
      $store: '@Freyr\MessageBroker\Inbox\DeduplicationStore'
    tags:
      - { name: 'messenger.middleware', priority: -10 }

  # MessageIdStamp Middleware
  Freyr\MessageBroker\Outbox\MessageIdStampMiddleware:
    tags:
      - { name: 'messenger.middleware' }

  # Outbox Publishing Middleware (core — transport-agnostic)
  # Publisher locator is populated by OutboxPublisherPass compiler pass
  Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware:
    arguments:
      $logger: '@logger'
    tags:
      - { name: 'messenger.middleware' }

  # AMQP Routing Strategy (convention-based with YAML overrides)
  Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface:
    class: Freyr\MessageBroker\Amqp\Routing\DefaultAmqpRoutingStrategy
    arguments:
      $defaultSenderName: 'amqp'
      $routingOverrides: '%message_broker.amqp.routing_overrides%'

  # AMQP Outbox Publisher (publishes outbox events to RabbitMQ)
  Freyr\MessageBroker\Amqp\AmqpOutboxPublisher:
    arguments:
      $senderLocator: !service_locator
        amqp: '@messenger.transport.amqp'
      $routingStrategy: '@Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface'
      $logger: '@logger'
    tags:
      - { name: 'message_broker.outbox_publisher', transport: 'outbox' }

  # Deduplication Store Cleanup Command (optional maintenance)
  Freyr\MessageBroker\Command\DeduplicationStoreCleanup:
    arguments:
      $connection: '@doctrine.dbal.default_connection'
    tags: ['console.command']
```

### 5. Create Database Migration

Create `migrations/VersionYYYYMMDDHHIISS.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Message Broker Deduplication table
 *
 * Creates deduplication tracking table with binary UUID v7 for middleware-based deduplication.
 */
final class Version20250103000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create message_broker_deduplication table for middleware-based deduplication';
    }

    public function up(Schema $schema): void
    {
        // Create message_broker_deduplication table with binary UUID v7
        $this->addSql("
            CREATE TABLE message_broker_deduplication (
                message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
                message_name VARCHAR(255) NOT NULL,
                processed_at DATETIME NOT NULL,
                INDEX idx_message_name (message_name),
                INDEX idx_processed_at (processed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE message_broker_deduplication');
    }
}
```

### 6. Add Environment Variables

Edit `.env`:

```env
###> freyr/message-broker ###
MESSENGER_AMQP_DSN=amqp://guest:guest@localhost:5672/%2f
###< freyr/message-broker ###
```

### 7. Run Migrations

```bash
php bin/console doctrine:migrations:migrate
```

### 8. Verify Installation

```bash
# Check bundle is registered
php bin/console debug:container | grep MessageBroker

# Check transports are available
php bin/console debug:messenger

# Start workers
php bin/console messenger:consume outbox -vv        # Outbox database → AMQP
php bin/console messenger:consume amqp_orders -vv  # AMQP → handlers
```