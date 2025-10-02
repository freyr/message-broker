# Freyr Messenger

**Reliable Inbox & Outbox Patterns for Symfony Messenger**

This package provides production-ready implementations of the Inbox and Outbox patterns for Symfony applications, ensuring reliable event publishing and consumption with transactional guarantees and automatic deduplication.

## Features

✅ **Transactional Outbox** - Publish events reliably with your business transactions
✅ **Automatic Deduplication** - Inbox pattern with binary UUID primary key deduplication
✅ **Symfony Messenger Integration** - Built on top of Symfony's robust messaging framework
✅ **AMQP/RabbitMQ Support** - Seamless integration with message brokers
✅ **Binary UUID v7** - Efficient storage and chronological ordering
✅ **Horizontal Scaling** - Support for multiple workers with SKIP LOCKED
✅ **Production Ready** - Battle-tested patterns with comprehensive error handling

## Quick Start

### Installation

1. Add to your `composer.json`:

```json
{
    "require": {
        "freyr/messenger": "^1.0"
    }
}
```

2. Run composer:
```bash
composer dump-autoload
```

### Configuration

#### 1. Messenger Configuration (`config/packages/messenger.yaml`)

```yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            # Outbox - stores events in database (transactional)
            outbox:
                dsn: 'doctrine://default?queue_name=outbox'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer'
                options:
                    auto_setup: false

            # Inbox - stores incoming events with deduplication
            inbox:
                dsn: 'inbox://default?queue_name=inbox'
                serializer: 'Freyr\Messenger\Inbox\Serializer\InboxEventSerializer'
                options:
                    auto_setup: true

            # AMQP - publishes to RabbitMQ
            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer'
                options:
                    auto_setup: true
                    exchange:
                        name: 'your.exchange'
                        type: fanout
                        durable: true

            # Failed - stores failed messages
            failed:
                dsn: 'doctrine://default?queue_name=failed'
                options:
                    auto_setup: false

        routing:
            # Route your domain events to outbox
            'App\Domain\Event\YourEvent': outbox

            # Route inbox messages to inbox transport
            'Freyr\Messenger\Inbox\Message\InboxEventMessage': inbox
```

#### 2. Doctrine Configuration (`config/packages/doctrine.yaml`)

Register the custom UUID type:

```yaml
doctrine:
    dbal:
        types:
            id_binary: Freyr\Messenger\Doctrine\Type\IdType
```

#### 3. Services Configuration (`config/services.yaml`)

```yaml
services:
    # Inbox Transport Factory
    Freyr\Messenger\Inbox\Transport\DoctrineDedupTransportFactory:
        arguments:
            $connection: '@doctrine.dbal.default_connection'
        tags: ['messenger.transport_factory']

    # Event Handler Registry
    Freyr\Messenger\Inbox\Handler\EventHandlerRegistry:
        arguments:
            $handlers: !tagged_iterator { tag: 'app.event_handler' }

    # Register your event handlers
    App\EventHandler\:
        resource: '../src/EventHandler/*'
        tags: ['app.event_handler']

    # AMQP Routing Strategy
    Freyr\Messenger\Outbox\Routing\AmqpRoutingStrategyInterface:
        class: Freyr\Messenger\Outbox\Routing\DefaultAmqpRoutingStrategy
        arguments:
            $exchangeName: 'your.exchange'
```

#### 4. Database Migration

Run migration to create `messenger_messages` table:

```bash
php bin/console doctrine:migrations:migrate
```

The inbox uses binary(16) UUID as primary key instead of bigint auto-increment.

#### 5. Environment Variables (`.env`)

```env
MESSENGER_AMQP_DSN=amqp://guest:guest@rabbitmq:5672/%2f
```

## Usage

### Outbox Pattern (Publishing Events)

#### 1. Define a Domain Event

```php
<?php

namespace App\Domain\Event;

use Freyr\Messenger\Outbox\MessageName;
use Freyr\Identity\Id;
use Carbon\CarbonImmutable;

#[MessageName('order.placed')]
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

#### 2. Dispatch the Event

```php
use Symfony\Component\Messenger\MessageBusInterface;

class OrderService
{
    public function __construct(
        private MessageBusInterface $eventBus,
    ) {}

    public function placeOrder(Order $order): void
    {
        // Save order (transaction started)
        $this->entityManager->persist($order);

        // Dispatch event (saved to outbox in same transaction)
        $this->eventBus->dispatch(new OrderPlaced(
            orderId: $order->getId(),
            customerId: $order->getCustomerId(),
            totalAmount: $order->getTotalAmount(),
            placedAt: CarbonImmutable::now()
        ));

        // Commit transaction (order + event saved atomically)
        $this->entityManager->flush();
    }
}
```

#### 3. Process Outbox

```bash
# Process outbox and publish to AMQP
php bin/console messenger:consume outbox -vv
```

### Inbox Pattern (Consuming Events)

#### 1. Consume from AMQP

Messages arriving via AMQP must have the following JSON structure:

```json
{
  "message_name": "order.placed",
  "message_id": "01234567-89ab-cdef-0123-456789abcdef",
  "payload": {
    "orderId": "...",
    "customerId": "..."
  }
}
```

**Required fields:**
- `message_name` (string): Message name in format `domain.subdomain.action` (aligns with `#[MessageName]` attribute)
- `message_id` (string): Unique UUID for deduplication (must be set by publisher)
- `payload` (object): Event data

```bash
# Consume from RabbitMQ and dispatch to inbox
php bin/console inbox:ingest --queue=your.queue
```

#### 2. Create Event Handler

**Option A: Generic Array-Based (Simple)**

```php
<?php

namespace App\EventHandler;

use Freyr\Messenger\EventHandlerInterface;

final readonly class OrderPlacedHandler implements EventHandlerInterface
{
    public function supports(): string
    {
        return 'order.placed';
    }

    public function handle(array $payload): void
    {
        $orderId = $payload['orderId'];
        $customerId = $payload['customerId'];

        // Process the event
        // ...
    }
}
```

Tag the handler in `services.yaml`:
```yaml
App\EventHandler\OrderPlacedHandler:
    tags: ['app.event_handler']
```

**Option B: Typed Message Handler (Recommended)**

Create a message class that matches your JSON structure:

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

Configure message type mapping in `config/services.yaml`:

```yaml
parameters:
    inbox.message_types:
        'order.placed': 'App\Message\OrderPlaced'

services:
    Freyr\Messenger\Inbox\Serializer\TypedInboxSerializer:
        arguments:
            $messageTypes: '%inbox.message_types%'
```

Update inbox transport to use TypedInboxSerializer in `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            inbox:
                dsn: 'inbox://default?queue_name=inbox'
                serializer: 'Freyr\Messenger\Inbox\Serializer\TypedInboxSerializer'
```

Create handler:
```php
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OrderPlacedHandler
{
    public function __invoke(OrderPlaced $message): void
    {
        // Type-safe access with IDE autocomplete!
        $orderId = $message->orderId;
        $customerId = $message->customerId;

        // Process...
    }
}
```

See [Typed Inbox Messages Guide](docs/inbox-typed-messages.md) for complete documentation.

#### 3. Process Inbox

```bash
# Process inbox with deduplication
php bin/console messenger:consume inbox -vv
```

## Running in Production

### Systemd Service Example

Create `/etc/systemd/system/messenger-inbox.service`:

```ini
[Unit]
Description=Messenger Inbox Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php bin/console messenger:consume inbox --time-limit=3600
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Start the service:
```bash
sudo systemctl enable messenger-inbox
sudo systemctl start messenger-inbox
```

### Supervisor Example

```ini
[program:messenger-inbox]
command=php /var/www/app/bin/console messenger:consume inbox --time-limit=3600
user=www-data
numprocs=4
autostart=true
autorestart=true
process_name=%(program_name)s_%(process_num)02d
```

### Docker Compose Example

```yaml
services:
  worker-inbox:
    image: your-app:latest
    command: php bin/console messenger:consume inbox --time-limit=3600
    restart: always
    deploy:
      replicas: 3
    environment:
      DATABASE_URL: mysql://user:pass@db:3306/app
      MESSENGER_AMQP_DSN: amqp://guest:guest@rabbitmq:5672/%2f
```

## Testing

### Test Deduplication

```bash
# Send 3 identical messages
php bin/console fsm:test-inbox-dedup

# Check database - should have only 1 row
php bin/console dbal:run-sql "SELECT HEX(id), queue_name FROM messenger_messages WHERE queue_name='inbox'"
```

## Monitoring

```bash
# View queue statistics
php bin/console messenger:stats

# View failed messages
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry
```

## Cleanup

```bash
# Clean up old outbox messages (older than 7 days)
php bin/console app:cleanup-outbox --days=7
```

## Architecture

For detailed architecture documentation, see:
- [Architecture Overview](../docs/architecture.md)
- [Inbox Pattern](../docs/inbox-implementation.md)
- [Outbox Pattern](../docs/outbox-pattern.md)

## Requirements

- PHP 8.4+
- Symfony 7.3+
- MySQL 8.0+ or MariaDB 10.5+ (binary UUID support)
- Doctrine DBAL 3+
- Doctrine ORM 3+
- freyr/identity 0.2+
- php-amqplib 3.7+

## Package Structure

```
messenger/
├── src/
│   ├── Doctrine/                   # Doctrine Integration
│   │   └── Type/                   # IdType (binary UUID v7 Doctrine type)
│   ├── Inbox/                      # Inbox Pattern
│   │   ├── Command/                # Console commands
│   │   ├── Handler/                # Message handlers
│   │   ├── Message/                # Message DTOs
│   │   ├── Serializer/             # Serialization
│   │   └── Transport/              # Custom transport
│   └── Outbox/                     # Outbox Pattern
│       ├── Command/                # Console commands
│       ├── EventBridge/            # Outbox-to-AMQP bridge
│       ├── Routing/                # Routing strategies
│       ├── Serializer/             # Serialization
│       └── MessageName.php         # Attribute for marking messages
├── config/                         # Configuration examples
├── docs/                           # Documentation
└── README.md
```

## Advanced Topics

### Custom Routing Strategy

Implement `AmqpRoutingStrategyInterface`:

```php
<?php

namespace App\Messenger;

use Freyr\Messenger\Outbox\Routing\AmqpRoutingStrategyInterface;

final readonly class CustomRoutingStrategy implements AmqpRoutingStrategyInterface
{
    public function getExchange(string $eventName): string
    {
        return match(true) {
            str_starts_with($eventName, 'order.') => 'orders',
            str_starts_with($eventName, 'user.') => 'users',
            default => 'events',
        };
    }

    public function getRoutingKey(string $eventName): string
    {
        return $eventName;
    }

    public function getHeaders(string $eventName): array
    {
        return [
            'event_name' => $eventName,
            'app_version' => '1.0.0',
        ];
    }
}
```

Register in services:
```yaml
Freyr\Messenger\Outbox\Routing\AmqpRoutingStrategyInterface:
    class: App\Messenger\CustomRoutingStrategy
```

## License

Proprietary - Freyr

## Support

For issues and questions, contact the development team.
