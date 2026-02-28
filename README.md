# Freyr Message Broker

Inbox and Outbox patterns for Symfony Messenger with transactional guarantees and automatic deduplication.

**This library is in early development. Not production ready.**

## Packages

| Package | Description |
|---|---|
| [freyr/message-broker](https://github.com/freyr/message-broker) | Core bundle — outbox publishing, inbox deduplication, serialisers |
| [freyr/message-broker-contracts](https://github.com/freyr/message-broker-contracts) | Shared interfaces, stamps, and attributes |
| [freyr/message-broker-amqp](https://github.com/freyr/message-broker-amqp) | AMQP transport plugin — RabbitMQ publishing, routing, topology management |

## Requirements

- PHP 8.2+
- Symfony 6.4+ or 7.0+
- Doctrine DBAL 3.0+ / ORM 3.0+
- MySQL or MariaDB

## Installation

```bash
composer require freyr/message-broker
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Freyr\MessageBroker\FreyrMessageBrokerBundle::class => ['all' => true],
];
```

For AMQP (RabbitMQ) support, also install the transport plugin:

```bash
composer require freyr/message-broker-amqp
```

```php
return [
    // ...
    Freyr\MessageBroker\FreyrMessageBrokerBundle::class => ['all' => true],
    Freyr\MessageBroker\Amqp\FreyrMessageBrokerAmqpBundle::class => ['all' => true],
];
```

## Configuration

### Bundle Configuration

Create `config/packages/message_broker.yaml`:

```yaml
message_broker:
    inbox:
        message_types:
            # 'order.placed': 'App\Message\OrderPlaced'
            # 'user.registered': 'App\Message\UserRegistered'
```

### Messenger Configuration

Create or update `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        failure_transport: failed

        default_middleware:
            enabled: true
            allow_no_handlers: false

        buses:
            messenger.bus.default:
                middleware:
                    - 'Freyr\MessageBroker\Outbox\MessageIdStampMiddleware'
                    - 'Freyr\MessageBroker\Outbox\MessageNameStampMiddleware'
                    - doctrine_transaction
                    - 'Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware'
                    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'

        transports:
            outbox:
                dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
                options:
                    auto_setup: true
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2

            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\MessageBroker\Serializer\WireFormatSerializer'
                options:
                    auto_setup: false

            amqp_orders:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'
                options:
                    auto_setup: false
                    queues:
                        orders_queue: ~
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2

            failed:
                dsn: 'doctrine://default?queue_name=failed'
                options:
                    auto_setup: true

        routing:
            # 'App\Domain\Event\OrderPlaced': outbox
```

### Environment Variables

Add to `.env`:

```env
MESSENGER_AMQP_DSN=amqp://guest:guest@localhost:5672/%2f
```

### Database

The package uses a three-table architecture:

| Table | Management | Purpose |
|---|---|---|
| `messenger_outbox` | Auto (`auto_setup: true`) | Outbox event storage |
| `messenger_messages` | Auto (`auto_setup: true`) | Failed messages |
| `message_broker_deduplication` | Manual migration | Inbox deduplication tracking |

The `messenger_outbox` and `messenger_messages` tables are created automatically on first worker run. Create a migration for the deduplication table:

```php
$this->addSql("
    CREATE TABLE message_broker_deduplication (
        message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
        message_name VARCHAR(255) NOT NULL,
        processed_at DATETIME NOT NULL,
        INDEX idx_dedup_processed_at (processed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
```

Run migrations:

```bash
php bin/console doctrine:migrations:migrate
```

## Workers

```bash
# Publish outbox events to AMQP
php bin/console messenger:consume outbox -vv

# Consume messages from AMQP
php bin/console messenger:consume amqp_orders -vv
```

## Ordered Outbox Delivery

For per-aggregate causal ordering with multiple outbox workers, use the ordered transport:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            outbox:
                dsn: 'ordered-doctrine://default?table_name=messenger_outbox&queue_name=outbox'
                options:
                    auto_setup: true
```

Add `PartitionKeyStampMiddleware` to your bus middleware and dispatch with a partition key:

```php
use Freyr\MessageBroker\Outbox\PartitionKeyStamp;

$this->bus->dispatch($orderPlaced, [
    new PartitionKeyStamp((string) $orderPlaced->orderId),
]);
```

Events with the same partition key are delivered to AMQP in insertion order. Events with different partition keys are processed in parallel across workers.

See [Ordered Delivery](docs/ordered-delivery.md) for the full guide.

## Documentation

- [Database Schema](docs/database-schema.md) — table architecture, migrations, and cleanup strategies
- [Outbox Pattern](docs/outbox-pattern.md) — transactional event publishing
- [Ordered Delivery](docs/ordered-delivery.md) — per-aggregate causal ordering with partition keys
- [Inbox Deduplication](docs/inbox-deduplication.md) — preventing duplicate message processing
- [Message Serialisation](docs/message-serialization.md) — semantic naming and cross-language compatibility
- [AMQP Routing](docs/amqp-routing.md) — convention-based routing with attribute overrides

## Licence

MIT
