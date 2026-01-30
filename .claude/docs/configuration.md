# Configuration Guide

## Database Schema - 3-Table Architecture

The package uses a **3-table approach** for optimal performance:

1. **`messenger_outbox`** - Auto-managed by Symfony (auto_setup: true)
2. **`message_broker_deduplication`** - Application-managed (manual migration)
3. **`messenger_messages`** - Auto-managed by Symfony (auto_setup: true)

See `docs/database-schema.md` for complete schemas, migration examples, and performance considerations.

## Messenger Configuration (messenger.yaml)

```yaml
framework:
  messenger:
    failure_transport: failed

    # Middleware configuration
    default_middleware:
      enabled: true
      allow_no_handlers: false

    buses:
      messenger.bus.default:
        middleware:
          - doctrine_transaction  # Priority 0
          # DeduplicationMiddleware (priority -10) registered via service tag

    transports:
      # Outbox transport - AUTO-MANAGED (auto_setup: true)
      outbox:
        dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        options:
          auto_setup: true  # Symfony creates table automatically
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # AMQP publish transport - MANUAL MANAGEMENT (auto_setup: false)
      amqp:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
        options:
          auto_setup: false  # Infrastructure managed by ops
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # AMQP consumption transport - MANUAL MANAGEMENT (auto_setup: false)
      amqp_orders:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'
        options:
          auto_setup: false  # Infrastructure managed by ops
          queue:
            name: 'orders_queue'
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2

      # Failed transport - AUTO-MANAGED (auto_setup: true)
      failed:
        dsn: 'doctrine://default?queue_name=failed'
        options:
          auto_setup: true  # Symfony creates table automatically

    routing:
    # Outbox messages - route domain events to outbox transport
    # Example:
    # 'App\Domain\Event\OrderPlaced': outbox
```

**Auto-Setup Policy:**
- **Doctrine transports (outbox, failed)**: `auto_setup: true` - Symfony manages tables automatically
- **AMQP transports**: `auto_setup: false` - Infrastructure managed separately
- **Deduplication table**: Manual migration required (custom binary UUID v7 schema)

## Doctrine Configuration

Register the custom UUID type in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        types:
            id_binary: Freyr\MessageBroker\Doctrine\Type\IdType
```

## Services Configuration

### Message Type Mapping

```yaml
message_broker:
  inbox:
    message_types:
      'order.placed': 'App\Message\OrderPlaced'
      'user.registered': 'App\Message\UserRegistered'
```

### Service Definitions

```yaml
services:
  _defaults:
    autowire: false
    autoconfigure: false
    public: false

  # Doctrine Integration
  Freyr\MessageBroker\Doctrine\Type\IdType:
    tags:
      - { name: 'doctrine.dbal.types', type: 'id_binary' }

  # Auto-register all Normalizers
  Freyr\MessageBroker\Serializer\Normalizer\:
    resource: '../src/Serializer/Normalizer/'
    tags: ['serializer.normalizer']

  # Custom ObjectNormalizer with property promotion support
  Freyr\MessageBroker\Serializer\Normalizer\PropertyPromotionObjectNormalizer:
    autowire: true
    class: Symfony\Component\Serializer\Normalizer\ObjectNormalizer
    arguments:
      $propertyTypeExtractor: '@property_info'
    tags:
      - { name: 'serializer.normalizer', priority: -1000 }

  # Inbox Serializer
  Freyr\MessageBroker\Serializer\InboxSerializer:
    arguments:
      $serializer: '@serializer'
      $messageTypes: '%message_broker.inbox.message_types%'

  # Outbox Serializer
  Freyr\MessageBroker\Serializer\OutboxSerializer:
    arguments:
      $serializer: '@serializer'

  # Deduplication Store
  Freyr\MessageBroker\Inbox\DeduplicationStore:
    class: Freyr\MessageBroker\Inbox\DeduplicationDbalStore
    arguments:
      $connection: '@doctrine.dbal.default_connection'
      $logger: '@logger'

  # Deduplication Middleware
  Freyr\MessageBroker\Inbox\DeduplicationMiddleware:
    arguments:
      $store: '@Freyr\MessageBroker\Inbox\DeduplicationStore'
    tags:
      - { name: 'messenger.middleware', priority: -10 }

  # AMQP Routing Strategy
  Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface:
    class: Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy

  # Outbox Bridge
  Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge:
    autoconfigure: true
    arguments:
      $eventBus: '@messenger.default_bus'
      $routingStrategy: '@Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface'
      $logger: '@logger'

  # Deduplication Cleanup Command
  Freyr\MessageBroker\Command\DeduplicationStoreCleanup:
    arguments:
      $connection: '@doctrine.dbal.default_connection'
    tags: ['console.command']
```
