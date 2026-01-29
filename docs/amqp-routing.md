# AMQP Routing Strategy

## Principle

AMQP routing determines which Symfony Messenger transport and routing keys are used when publishing events to RabbitMQ. Since Symfony Messenger requires exchanges to be configured statically in transport DSN, this package uses transport-based routing.

## Default Convention-Based Routing

**Strategy:**
- **Transport**: Default `amqp` transport (configured in messenger.yaml)
- **Routing Key**: Full message name (e.g., `order.placed`)
- **Headers**: `x-message-name` and domain-specific headers

**Examples:**
- `order.placed` → transport: `amqp`, key: `order.placed`
- `inventory.stock.received` → transport: `amqp`, key: `inventory.stock.received`
- `user.premium.upgraded` → transport: `amqp`, key: `user.premium.upgraded`

## Attribute-Based Overrides

**Custom Transport:**
```php
#[MessageName('order.placed')]
#[MessengerTransport('commerce')]  // Override transport
final class OrderPlaced implements OutboxMessage { }
```
Result: transport: `commerce`, key: `order.placed`

**Custom Routing Key:**
```php
#[MessageName('order.placed')]
#[AmqpRoutingKey('inventory.orders.placed')]
final class OrderPlaced implements OutboxMessage { }
```
Result: transport: `amqp`, key: `inventory.orders.placed`

**Both Overrides:**
```php
#[MessageName('order.placed')]
#[MessengerTransport('commerce')]
#[AmqpRoutingKey('commerce.order.created')]
final class OrderPlaced implements OutboxMessage { }
```
Result: transport: `commerce`, key: `commerce.order.created`

## Benefits

**Convention over configuration:** Zero routing config for standard cases

**Transport-based isolation:** Use different transports for different domains (e.g., `commerce`, `inventory`, `billing`)

**Flexibility:** Attributes override defaults when needed

**Symfony-native:** Works with Symfony Messenger's transport configuration system

## Architecture

```
[Event] → #[MessageName('order.placed')]
            #[MessengerTransport('commerce')] (optional)
            #[AmqpRoutingKey('inventory.orders.placed')] (optional)
              ↓
    [AmqpRoutingStrategyInterface]
              ↓
    [DefaultAmqpRoutingStrategy]
              ↓
    transport: 'commerce', routing_key: 'inventory.orders.placed', headers: {...}
              ↓
    [TransportNamesStamp] → routes to specified Messenger transport
              ↓
        [AMQP Publish]
```

## Key Components

- **AmqpRoutingStrategyInterface** - Contract for routing logic
- **DefaultAmqpRoutingStrategy** - Convention-based implementation
- **MessengerTransport attribute** - Override Symfony Messenger transport name
- **AmqpRoutingKey attribute** - Override routing key
- **OutboxToAmqpBridge** - Applies routing during publishing

## Message Headers

Every AMQP message includes:
- `message_name` - Semantic name for filtering/routing
- `content_type` - application/json
- Auto-generated stamps in `X-Message-Stamp-*` headers

## Transport Configuration

Each transport must be configured in `messenger.yaml` with its AMQP exchange:

```yaml
framework:
  messenger:
    transports:
      # Default transport
      amqp:
        dsn: '%env(MESSENGER_AMQP_DSN)%'  # amqp://user:pass@host:5672/vhost?exchange[name]=default.events
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'

      # Domain-specific transport
      commerce:
        dsn: '%env(MESSENGER_AMQP_DSN)%?exchange[name]=commerce.events'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
```

**Publisher side:**
- Uses `TransportNamesStamp` to route to specified transport
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
Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface:
    class: App\Infrastructure\CustomAmqpRoutingStrategy
```

Implement interface:
- `getTransport(object $event): string`
- `getRoutingKey(object $event, string $messageName): string`
- `getHeaders(string $messageName): array`
