# Freyr Message Broker

**Production-Ready Inbox & Outbox Patterns for Symfony Messenger**

A Symfony bundle providing reliable event publishing and consumption with transactional guarantees, automatic deduplication, and seamless AMQP integration.

## Features

- ✅ **Transactional Outbox** - Publish events reliably within your business transactions
- ✅ **Automatic Deduplication at the Inbox** - Binary UUID v7 primary key prevents duplicate processing
- ✅ **Typed Message Handlers** - Type-safe event consumption with IDE autocomplete
- ✅ **Automatic DLQ Routing** - Unmatched events routed to dead-letter queue
- ✅ **Horizontal Scaling** - Multiple workers with database-level SKIP LOCKED

## Restrictions
- **Only Mysql Support** - (planned) PostgresSQL support
- **Zero Configuration** - (in progress) Symfony Flex recipe automates installation
- **AMQP support only** - There is no plan do add Kafka/SQS etc.

## Quick Start

### Installation

```bash
composer require freyr/message-broker
```

**That's it!** Symfony Flex automatically:
- ✅ Registers the bundle
- ✅ Creates configuration files
- ✅ Adds database migrations
- ✅ Sets up environment variables

### Setup Database

```bash
php bin/console doctrine:migrations:migrate
```

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

5. `php bin/console inbox:ingest --queue=inventory_stock` fetches events from AMQP and saves to inbox database
   - Uses Doctrine transport with UUID v7 (binary(16)) as primary key
   - Uses INSERT IGNORE for automatic deduplication
   - PK is extracted from the `message_id` field, preventing duplicate processing

6. `php bin/console messenger:consume inbox -vv` fetches events from inbox database and dispatches to handlers
   - Pure Symfony Messenger flow with typed message deserialization
   - Messages deserialized based on `message_name` (must match sender, e.g., `inventory.stock.received`)
   - Doctrine transport uses `SELECT ... FOR UPDATE SKIP LOCKED` for concurrency
   - Message fetch, handler execution, and acknowledgment happen atomically

### Start Workers

```bash
# Process outbox (publish events to AMQP)
php bin/console messenger:consume outbox -vv

# Consume from AMQP and save to inbox database
php bin/console inbox:ingest --queue=your.queue

# Process inbox database (dispatch to handlers)
php bin/console messenger:consume inbox -vv


```

## Usage

### Publishing Events (Outbox Pattern)

#### 1. Define Your Event

```php
<?php

namespace App\Domain\Event;

use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\Identity\Id;
use Carbon\CarbonImmutable;

#[MessageName('order.placed')]
final readonly class OrderPlaced
{
    public function __construct(
        public Id $messageId,        // Required for correlation
        public Id $orderId,
        public Id $customerId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

**Important:** All outbox events MUST have:
- `#[MessageName('domain.subdomain.action')]` attribute
- Public `messageId` property of type `Id` (UUID v7)

**AMQP Routing:**

By default, events are published using convention-based routing:
- **Exchange**: First 2 parts of message name (`order.placed` → `order.placed`)
- **Routing Key**: Full message name (`order.placed`)

You can override this with attributes:

```php
use Freyr\MessageBroker\Outbox\Routing\AmqpExchange;
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingKey;

#[MessageName('order.placed')]
#[AmqpExchange('commerce')]           // Custom exchange
#[AmqpRoutingKey('commerce.order.placed')]   // Custom routing key
final readonly class OrderPlaced
{
    // ...
}
```

See [AMQP Routing Guide](docs/amqp-routing-guide.md) for complete documentation.

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
        $this->messageBus->dispatch(new OrderPlaced(
            messageId: Id::new(),
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
        public Id $messageId,      // Must match publisher
        public Id $orderId,
        public Id $customerId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

**Important:** Consumer message properties must match the publisher event exactly.

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
# Ingest from AMQP → Inbox transport
php bin/console inbox:ingest --queue=your.queue

# Process inbox messages → Handlers
php bin/console messenger:consume inbox -vv
```

### Message Format

AMQP messages must follow this JSON structure:

```json
{
  "message_name": "order.placed",
  "message_id": "01234567-89ab-cdef-0123-456789abcdef",
  "payload": {
    "messageId": "01234567-89ab-cdef-0123-456789abcdef",
    "orderId": "...",
    "customerId": "...",
    "totalAmount": 99.99,
    "placedAt": "2024-01-01T12:00:00+00:00"
  }
}
```

**Required fields:**
- `message_name` - Matches `#[MessageName]` attribute
- `message_id` - Used as primary key for deduplication
- `payload` - Must contain all consumer message constructor parameters

## Configuration

### After Installation

Symfony Flex creates these configuration files:

**config/packages/message_broker.yaml:**
```yaml
message_broker:
    inbox:
        table_name: messenger_inbox
        message_types: {}  # Add your mappings here
        failed_transport: failed
    outbox:
        table_name: messenger_outbox
        dlq_transport: dlq
```

**config/packages/messenger.yaml:**
```yaml
framework:
    messenger:
        transports:
            outbox:
                dsn: 'outbox://default?table_name=messenger_outbox&queue_name=outbox'
            inbox:
                dsn: 'inbox://default?table_name=messenger_inbox&queue_name=inbox'
            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
            dlq:
                dsn: 'doctrine://default?queue_name=dlq'
            failed:
                dsn: 'doctrine://default?queue_name=failed'
        routing:
            'Freyr\MessageBroker\Inbox\Message\InboxEventMessage': inbox
            # Add your domain events here:
            # 'App\Domain\Event\OrderPlaced': outbox
```

**.env:**
```env
MESSENGER_AMQP_DSN=amqp://guest:guest@localhost:5672/%2f
```

### Customization

You can customize:
- Table names in `message_broker.yaml`
- Transport DSNs in `messenger.yaml`
- AMQP connection in `.env`
- Failed/DLQ transport names

## Production Deployment

### Docker Compose

```yaml
services:
  # Consume from AMQP and save to inbox database
  worker-inbox-ingest:
    image: your-app:latest
    command: php bin/console inbox:ingest --queue=your.queue
    restart: always
    deploy:
      replicas: 2

  # Process outbox database and publish to AMQP
  worker-outbox:
    image: your-app:latest
    command: php bin/console messenger:consume outbox --time-limit=3600
    restart: always
    deploy:
      replicas: 2

  # Process inbox database and dispatch to handlers
  worker-inbox:
    image: your-app:latest
    command: php bin/console messenger:consume inbox --time-limit=3600
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

### Cleanup (Optional)

```bash
# Clean up old delivered messages (older than 7 days)
php bin/console messenger:cleanup-outbox --days=7 --batch-size=1000
```

**Note:** Symfony Messenger marks messages as delivered but doesn't auto-delete them. Run this periodically via cron to prevent table growth.

## Architecture

### 3-Table Design

The bundle uses dedicated tables for optimal performance:

- **messenger_outbox** - Outbox events (binary UUID v7)
- **messenger_inbox** - Inbox events with deduplication (binary UUID v7 from `message_id`)
- **messenger_messages** - Failed/DLQ messages (standard bigint)

**Benefits:**
- No lock contention between inbox/outbox
- Optimized indexes per use case
- Independent cleanup policies
- Unified failed message monitoring

### Flow Diagrams

**Outbox (Publishing):**
```
Domain Event → Message Bus → Outbox Transport (database)
→ messenger:consume outbox → OutboxToAmqpBridge
→ PublishingStrategyRegistry → AmqpPublishingStrategy → AMQP
```

**Inbox (Consuming):**
```
AMQP → inbox:ingest → InboxEventMessage → Inbox Transport (database)
→ INSERT IGNORE (deduplication) → messenger:consume inbox
→ InboxSerializer → Typed Message → Your Handler
```

See [Architecture Documentation](docs/) for detailed explanations.

## Requirements

- PHP 8.4+
- Symfony 6.4+ or 7.0+
- MySQL 8.0+ or MariaDB 10.5+ (binary UUID support)
- Doctrine DBAL 3+
- Doctrine ORM 3+ (optional, for entities)
- freyr/identity 0.2+ (UUID v7 support)
- php-amqplib 3.7+ (for AMQP)

## Documentation

- [Architecture Overview](docs/architecture.md)
- [Outbox Pattern Guide](docs/outbox-pattern.md)
- [Inbox Implementation](docs/inbox-implementation.md)
- [Database Schema](docs/database-schema.md)
- [AMQP Routing Guide](docs/amqp-routing-guide.md)

## Troubleshooting

**Issue:** Events not being published
- Check outbox worker is running: `php bin/console messenger:consume outbox -vv`
- Verify event is routed to outbox in `messenger.yaml`

**Issue:** Duplicate messages being processed
- Ensure `message_id` is unique and consistent
- Check inbox table has binary UUID primary key

**Issue:** Messages not consumed from AMQP
- Verify RabbitMQ queue exists with proper bindings
- Check AMQP DSN in `.env` is correct
- Ensure message format matches required JSON structure

**Issue:** Missing required parameter errors
- Consumer message properties must match publisher event exactly
- All constructor parameters must be present in payload

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
        table_name: messenger_inbox
        message_types: {}  # Add your message type mappings here
        failed_transport: failed
    outbox:
        table_name: messenger_outbox
        dlq_transport: dlq
```

**config/packages/messenger.yaml:**

```yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            # Outbox - stores events in database with binary UUID v7
            outbox:
                dsn: 'outbox://default?table_name=messenger_outbox&queue_name=outbox'
                serializer: 'Freyr\MessageBroker\Outbox\Serializer\OutboxSerializer'
                options:
                    auto_setup: false

            # Inbox - custom transport with deduplication
            inbox:
                dsn: 'inbox://default?table_name=messenger_inbox&queue_name=inbox'
                serializer: 'Freyr\MessageBroker\Inbox\Serializer\InboxSerializer'
                options:
                    auto_setup: false

            # AMQP - external message broker
            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\MessageBroker\Outbox\Serializer\OutboxSerializer'
                options:
                    auto_setup: false
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2

            # Dead Letter Queue - for unmatched outbox events
            dlq:
                dsn: 'doctrine://default?queue_name=dlq'
                options:
                    auto_setup: false

            # Failed transport - for all failed messages
            failed:
                dsn: 'doctrine://default?queue_name=failed'
                options:
                    auto_setup: false

        routing:
            # Route InboxEventMessage to inbox transport
            'Freyr\MessageBroker\Inbox\Message\InboxEventMessage': inbox

            # Add your domain events routing here:
            # 'App\Domain\Event\OrderPlaced': outbox
```

**config/packages/doctrine.yaml:**

```yaml
doctrine:
    dbal:
        types:
            id_binary: Freyr\MessageBroker\Doctrine\Type\IdType
```

### 4. Create Database Migration

Create `migrations/VersionYYYYMMDDHHIISS.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class VersionYYYYMMDDHHIISS extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Freyr Message Broker tables (outbox, inbox, messages)';
    }

    public function up(Schema $schema): void
    {
        // Create messenger_outbox table with binary UUID v7
        $this->addSql("
            CREATE TABLE messenger_outbox (
                id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT 'outbox',
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create messenger_inbox table with binary UUID v7
        $this->addSql("
            CREATE TABLE messenger_inbox (
                id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT 'inbox',
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create standard messenger_messages table (for failed/DLQ)
        $this->addSql("
            CREATE TABLE messenger_messages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_outbox');
        $this->addSql('DROP TABLE messenger_inbox');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
```

### 5. Add Environment Variables

Edit `.env`:

```env
###> freyr/message-broker ###
MESSENGER_AMQP_DSN=amqp://guest:guest@localhost:5672/%2f
###< freyr/message-broker ###
```

### 6. Run Migrations

```bash
php bin/console doctrine:migrations:migrate
```

### 7. Verify Installation

```bash
# Check bundle is registered
php bin/console debug:container | grep MessageBroker

# Check transports are available
php bin/console debug:messenger

# Start workers
php bin/console inbox:ingest --queue=your.queue    # AMQP → inbox database
php bin/console messenger:consume outbox -vv       # Outbox database → AMQP
php bin/console messenger:consume inbox -vv        # Inbox database → handlers
```

You're now ready to use the bundle! Continue with the [Usage](#usage) section above.

## License

Proprietary - Freyr

## Support

For issues and questions, contact the development team.
