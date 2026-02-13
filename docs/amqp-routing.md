# AMQP Routing Strategy

## Principle

AMQP routing determines which Symfony Messenger transport and routing keys are used when publishing events to RabbitMQ. Since Symfony Messenger requires exchanges to be configured statically in transport DSN, this package uses transport-based routing.

## Default Convention-Based Routing

**Strategy:**
- **Transport**: Default `amqp` transport (configured in messenger.yaml)
- **Routing Key**: Full message name (e.g., `order.placed`)
- **Headers**: `x-message-name` header

**Examples:**
- `order.placed` → transport: `amqp`, key: `order.placed`
- `inventory.stock.received` → transport: `amqp`, key: `inventory.stock.received`
- `user.premium.upgraded` → transport: `amqp`, key: `user.premium.upgraded`

## Override Precedence

Routing can be overridden at three levels (highest priority first):

1. **YAML configuration** — `message_broker.amqp.routing`
2. **PHP attributes** — `#[AmqpExchange]`, `#[AmqpRoutingKey]`
3. **Convention** — default sender + full message name as routing key

## YAML-Based Overrides (Preferred)

Override routing for specific message names in `config/packages/message_broker.yaml`:

```yaml
message_broker:
    amqp:
        routing:
            'order.placed':
                sender: commerce                 # Publish via 'commerce' transport
                routing_key: commerce.orders.new  # Custom routing key
            'user.registered':
                sender: identity                 # Publish via 'identity' transport
```

YAML overrides take precedence over attributes.

## Attribute-Based Overrides

**Custom Transport:**
```php
use Freyr\MessageBroker\Amqp\Routing\AmqpExchange;

#[MessageName('order.placed')]
#[AmqpExchange('commerce')]  // Override transport
final class OrderPlaced implements OutboxMessage { }
```
Result: transport: `commerce`, key: `order.placed`

**Custom Routing Key:**
```php
use Freyr\MessageBroker\Amqp\Routing\AmqpRoutingKey;

#[MessageName('order.placed')]
#[AmqpRoutingKey('inventory.orders.placed')]
final class OrderPlaced implements OutboxMessage { }
```
Result: transport: `amqp`, key: `inventory.orders.placed`

**Both Overrides:**
```php
use Freyr\MessageBroker\Amqp\Routing\AmqpExchange;
use Freyr\MessageBroker\Amqp\Routing\AmqpRoutingKey;

#[MessageName('order.placed')]
#[AmqpExchange('commerce')]
#[AmqpRoutingKey('commerce.order.created')]
final class OrderPlaced implements OutboxMessage { }
```
Result: transport: `commerce`, key: `commerce.order.created`

## Architecture

```
[Event] → #[MessageName('order.placed')]
            #[AmqpExchange('commerce')] (optional)
            #[AmqpRoutingKey('inventory.orders.placed')] (optional)
              ↓
    [OutboxPublishingMiddleware] (core — transport-agnostic)
              ↓
    [AmqpOutboxPublisher] (AMQP plugin)
              ↓
    [AmqpRoutingStrategyInterface]
              ↓
    [DefaultAmqpRoutingStrategy]
        1. Check YAML overrides
        2. Check attribute overrides
        3. Fall back to convention
              ↓
    sender: 'commerce', routing_key: 'inventory.orders.placed', headers: {...}
              ↓
    [SenderLocator] → resolves SenderInterface by name
              ↓
        [AMQP Publish]
```

## Key Components

- **`Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface`** — Contract for routing logic
- **`Freyr\MessageBroker\Amqp\Routing\DefaultAmqpRoutingStrategy`** — Convention-based implementation with YAML overrides
- **`Freyr\MessageBroker\Amqp\Routing\AmqpExchange`** — Attribute to override Symfony Messenger transport name
- **`Freyr\MessageBroker\Amqp\Routing\AmqpRoutingKey`** — Attribute to override routing key
- **`Freyr\MessageBroker\Amqp\AmqpOutboxPublisher`** — Applies routing during publishing

## Message Headers

Every AMQP message includes:
- `x-message-name` — Semantic name for filtering/routing
- `content_type` — application/json
- Auto-generated stamps in `X-Message-Stamp-*` headers

## Transport Configuration

Each transport must be configured in `messenger.yaml` with its AMQP exchange:

```yaml
framework:
  messenger:
    transports:
      # Default transport
      amqp:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'

      # Domain-specific transport
      commerce:
        dsn: '%env(MESSENGER_AMQP_DSN)%?exchange[name]=commerce.events'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
```

**Publisher side:**
- `AmqpOutboxPublisher` resolves sender via `SenderLocator` by transport name
- Transport DSN defines the AMQP exchange
- Routing key set dynamically via `AmqpStamp`

**Consumer side (RabbitMQ):**
- Create queue: `inventory_events`
- Bind to exchange: `commerce.events` (from transport DSN)
- Binding pattern: `inventory.*` or specific keys

This receives all events matching the pattern.

## Custom Strategy

Replace default strategy in services.yaml:
```yaml
Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface:
    class: App\Infrastructure\CustomAmqpRoutingStrategy
```

Implement interface:
- `getSenderName(object $event, string $messageName): string`
- `getRoutingKey(object $event, string $messageName): string`
- `getHeaders(string $messageName): array`
