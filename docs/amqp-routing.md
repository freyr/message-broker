# AMQP Routing Strategy

## Principle

AMQP routing determines which exchange and routing key are used when publishing events to RabbitMQ. The package uses convention-based defaults with attribute-based overrides.

## Default Convention-Based Routing

**Strategy:**
- **Exchange**: First 2 parts of message name (e.g., `order.placed` → `order.placed`)
- **Routing Key**: Full message name (e.g., `order.placed`)
- **Headers**: `message_name` header set to semantic name

**Examples:**
- `order.placed` → exchange: `order.placed`, key: `order.placed`
- `inventory.stock.received` → exchange: `inventory.stock`, key: `inventory.stock.received`
- `user.premium.upgraded` → exchange: `user.premium`, key: `user.premium.upgraded`

## Attribute-Based Overrides

**Custom Exchange:**
```php
#[MessageName('order.placed')]
#[AmqpExchange('commerce')]  // Override exchange
final class OrderPlaced implements OutboxMessage { }
```
Result: exchange: `commerce`, key: `order.placed`

**Custom Routing Key:**
```php
#[MessageName('order.placed')]
#[AmqpRoutingKey('inventory.orders.placed')] 
final class OrderPlaced implements OutboxMessage { }
```
Result: exchange: `order.placed`, key: `inventory.orders.placed`

**Both Overrides:**
```php
#[MessageName('order.placed')]
#[AmqpExchange('commerce')]
#[AmqpRoutingKey('commerce.order.created')]
final class OrderPlaced implements OutboxMessage { }
```
Result: exchange: `commerce`, key: `commerce.order.created`

## Benefits

**Convention over configuration:** Zero routing config for standard cases

**Namespace isolation:** First 2 parts create logical domain boundaries

**Flexibility:** Attributes override defaults when needed

## Architecture

```
[Event] → #[MessageName('order.placed')]
            #[AmqpExchange('commerce')] (optional)
            #[AmqpRoutingKey('inventory.orders.placed')] (optional)
              ↓
    [AmqpRoutingStrategyInterface]
              ↓
    [DefaultAmqpRoutingStrategy]
              ↓
    exchange: 'commerce', routing_key: 'inventory.orders.placed', headers: {...}
              ↓
        [AMQP Publish]
```

## Key Components

- **AmqpRoutingStrategyInterface** - Contract for routing logic
- **DefaultAmqpRoutingStrategy** - Convention-based implementation
- **AmqpExchange attribute** - Override exchange name
- **AmqpRoutingKey attribute** - Override routing key
- **OutboxToAmqpBridge** - Applies routing during publishing

## Message Headers

Every AMQP message includes:
- `message_name` - Semantic name for filtering/routing
- `content_type` - application/json
- Auto-generated stamps in `X-Message-Stamp-*` headers

## Consumer Binding Strategy

**Publisher side:**
- Publishes to exchange with routing key

**Consumer side (RabbitMQ):**
- Create queue: `inventory_events`
- Bind to exchange: `inventory.stock`
- Binding pattern: `inventory.stock.*`

This receives all events matching the pattern regardless of specific routing key.

## Custom Strategy

Replace default strategy in services.yaml:
```yaml
Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface:
    class: App\Infrastructure\CustomAmqpRoutingStrategy
```

Implement interface:
- `getExchange(object $event, string $messageName): string`
- `getRoutingKey(object $event, string $messageName): string`
- `getHeaders(string $messageName): array`
