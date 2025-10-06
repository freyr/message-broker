# AMQP Routing Guide

## Overview

The Freyr Messenger outbox uses a **convention-based routing strategy** with **attribute-based overrides** for AMQP message routing.

## Default Routing Convention

### Exchange Name
**Rule:** First 2 parts of the message name

```
order.placed           → exchange: order.placed
sla.calculation.started → exchange: sla.calculation
user.account.created   → exchange: user.account
```

### Routing Key
**Rule:** Full message name (always a concrete value, NO wildcards)

```
order.placed           → routing_key: order.placed
sla.calculation.started → routing_key: sla.calculation.started
user.account.created   → routing_key: user.account.created
```

**Important:** Routing keys are set by the **publisher** and must be concrete strings. They cannot contain wildcards like `*` or `#`. Wildcards are used in **binding keys** on the consumer side when binding queues to topic exchanges.

### AMQP Headers
Additional metadata headers are automatically added:

```php
[
    'x-message-name' => 'order.placed',
    'x-message-domain' => 'order',
    'x-message-subdomain' => 'placed',
    'x-message-action' => 'unknown'  // If less than 3 parts
]
```

## Examples

### Example 1: Standard Event

```php
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\Identity\Id;

#[MessageName('order.placed')]
final readonly class OrderPlaced
{
    public function __construct(
        public Id $messageId,
        public Id $orderId,
        public float $amount,
    ) {}
}
```

**AMQP Routing:**
- Exchange: `order.placed`
- Routing Key: `order.placed` (concrete value)

**Consumer Binding Keys** (on queue side):
- `order.*` - Receives all order events
- `order.placed` - Receives only order.placed events
- `*.placed` - Receives all placed events across domains

### Example 2: Multi-Part Event

```php
#[MessageName('sla.calculation.started')]
final readonly class SlaCalculationStarted
{
    public function __construct(
        public Id $messageId,
        public Id $slaId,
    ) {}
}
```

**AMQP Routing:**
- Exchange: `sla.calculation`
- Routing Key: `sla.calculation.started`
- Headers: `x-message-action: 'started'`

## Attribute-Based Overrides

### Override Exchange

Use `#[AmqpExchange]` to specify a custom exchange:

```php
use Freyr\Messenger\Outbox\Routing\AmqpExchange;

#[MessageName('order.placed')]
#[AmqpExchange('commerce.events')]  // Custom exchange
final readonly class OrderPlaced
{
    // Exchange: commerce.events (overridden)
    // Routing Key: order.placed (default)
}
```

**Use Cases:**
- Legacy system compatibility
- Shared exchanges across multiple domains
- Special routing for critical events

### Override Routing Key

Use `#[AmqpRoutingKey]` to specify a custom routing key (must be concrete, NO wildcards):

```php
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingKey;

#[MessageName('user.premium.upgraded')]
#[AmqpRoutingKey('user.upgraded')]  // Simplified routing key
final readonly class UserPremiumUpgraded
{
    // Exchange: user.premium (default)
    // Routing Key: user.upgraded (overridden)
}
```

**Use Cases:**
- Legacy system compatibility (specific routing key format)
- Simplified routing keys for consolidation
- Custom routing schemes
- Backward compatibility with existing queues

**Important:** The `#[AmqpRoutingKey]` attribute sets the routing key used by the **publisher**. It must be a concrete string value. To match multiple routing keys, use wildcard **binding keys** when binding queues to exchanges on the consumer side.

### Override Both

```php
#[MessageName('legacy.system.notification')]
#[AmqpExchange('legacy.events')]
#[AmqpRoutingKey('legacy.notification')]  // Concrete value, no wildcards
final readonly class LegacySystemNotification
{
    // Exchange: legacy.events (overridden)
    // Routing Key: legacy.notification (overridden)
}
```

## Understanding Routing Keys vs Binding Keys

### Routing Key (Publisher Side)
- Set by the **publisher** when sending a message
- Must be a **concrete string** value (e.g., `order.placed`, `user.registered`)
- **NO wildcards allowed** (`*` or `#`)
- Specified via `#[AmqpRoutingKey]` attribute or derived from message name

### Binding Key (Consumer Side)
- Set when **binding a queue to an exchange**
- **CAN use wildcards** for topic exchanges:
  - `*` (star) - matches exactly one word
  - `#` (hash) - matches zero or more words
- Examples: `order.*`, `*.placed`, `user.#`

**Example:**
```
Publisher sends:         routing_key: "order.placed"
Queue bound with:        binding_key: "order.*"
Result: Message matches! ✅

Publisher sends:         routing_key: "user.registered"
Queue bound with:        binding_key: "order.*"
Result: No match ❌
```

## RabbitMQ Exchange Setup

### Topic Exchange (Recommended)

```bash
# Create topic exchange
rabbitmqadmin declare exchange name=order.placed type=topic durable=true

# Create queue
rabbitmqadmin declare queue name=order.processing durable=true

# Bind queue with BINDING KEY pattern (consumer side - wildcards allowed)
rabbitmqadmin declare binding source=order.placed destination=order.processing routing_key="order.*"
```

**Binding Key Patterns:**
- `order.*` - Matches all order events (e.g., `order.placed`, `order.cancelled`)
- `order.placed` - Matches only `order.placed` events (exact match)
- `*.placed` - Matches all placed events across domains (e.g., `order.placed`, `user.placed`)

### Declarative Setup (AmqpSetupCommand)

Use the inbox setup command as reference for declarative AMQP infrastructure:

```php
// See: src/Inbox/Command/AmqpSetupCommand.php
$config = [
    'exchanges' => [
        [
            'name' => 'order.placed',
            'type' => 'topic',
            'durable' => true,
        ],
    ],
    'queues' => [
        [
            'name' => 'order.processing',
            'durable' => true,
        ],
    ],
    'bindings' => [
        [
            'queue' => 'order.processing',
            'exchange' => 'order.placed',
            'routing_key' => 'order.*',  // BINDING KEY - wildcards allowed!
        ],
    ],
];
```

**Note:** The `routing_key` in bindings is actually a **binding key** (consumer side), so wildcards like `*` and `#` are allowed here.

## Routing Strategy Configuration

### Default Strategy

```yaml
# config/services.yaml
services:
    Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface:
        class: Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy
```

The default strategy:
1. Checks for `#[AmqpExchange]` attribute
2. Falls back to first 2 parts of message name
3. Checks for `#[AmqpRoutingKey]` attribute
4. Falls back to full message name

### Custom Strategy

Implement your own routing logic:

```php
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface;

final readonly class CustomAmqpRoutingStrategy implements AmqpRoutingStrategyInterface
{
    public function getExchange(object $event, string $messageName): string
    {
        // Custom logic based on event properties
        if ($event instanceof CriticalEvent) {
            return 'critical.events';
        }

        // Fall back to default
        return explode('.', $messageName)[0];
    }

    public function getRoutingKey(object $event, string $messageName): string
    {
        // Custom routing key logic - MUST return concrete string, NO wildcards
        return $messageName;
    }

    public function getHeaders(string $messageName): array
    {
        return [
            'x-message-name' => $messageName,
            'x-app-version' => '1.0.0',
        ];
    }
}
```

## Best Practices

### 1. Use Semantic Message Names

```php
// ✅ Good - Clear hierarchy
#[MessageName('order.placed')]
#[MessageName('order.payment.processed')]
#[MessageName('user.account.created')]

// ❌ Bad - Unclear structure
#[MessageName('placed')]
#[MessageName('evt_order')]
```

### 2. Follow Naming Convention

Format: `{domain}.{subdomain}.{action}`

- **Domain**: Top-level business area (order, user, sla)
- **Subdomain**: Specific area within domain (payment, account, calculation)
- **Action**: What happened (placed, created, started, completed)

### 3. Use Topic Exchanges

Topic exchanges provide the most flexibility for routing:

```bash
# One event, multiple consumers
order.placed → [order.processing, analytics.orders, notification.service]
```

### 4. Avoid Overrides Unless Necessary

Use the default convention-based routing. Only override when:
- Integrating with legacy systems
- Consolidating multiple event types
- Special routing requirements

### 5. Document Custom Routing

When using overrides, document the reasoning:

```php
/**
 * Legacy System Event.
 *
 * Uses custom AMQP routing for backward compatibility with legacy.events exchange.
 * This event is routed to the legacy system before being phased out in Q3 2025.
 */
#[MessageName('user.migrated')]
#[AmqpExchange('legacy.events')]
#[AmqpRoutingKey('user.migration')]  // Concrete routing key
final readonly class UserMigrated { ... }
```

## Monitoring & Debugging

### View Exchange Bindings

```bash
# List exchanges
rabbitmqctl list_exchanges name type durable

# List bindings for an exchange
rabbitmqctl list_bindings source_name=order.placed

# View queue bindings
rabbitmqctl list_bindings destination_name=order.processing
```

### Test Routing

```php
// Send test event
$this->eventBus->dispatch(new OrderPlaced(
    messageId: Id::generate(),
    orderId: Id::generate(),
    amount: 100.00,
));

// Check logs
tail -f var/log/messenger.log | grep "Publishing event to AMQP"
```

Expected log output:
```
[info] Publishing event to AMQP {
    "message_name": "order.placed",
    "exchange": "order.placed",
    "routing_key": "order.placed"
}
```

## Common Patterns

### Pattern 1: Domain-Specific Exchanges

Each domain has its own exchange:

```php
#[MessageName('order.placed')]      // → order.placed exchange
#[MessageName('order.cancelled')]   // → order.cancelled exchange
#[MessageName('user.registered')]   // → user.registered exchange
```

Consumers bind to specific domains they care about.

### Pattern 2: Consolidated Exchange

Override to use shared exchange:

```php
#[MessageName('order.placed')]
#[AmqpExchange('commerce')]

#[MessageName('payment.processed')]
#[AmqpExchange('commerce')]
```

All commerce events → single `commerce` exchange with topic routing.

### Pattern 3: Simplified Routing Keys

```php
#[MessageName('notification.email.sent')]
#[AmqpRoutingKey('notification.sent')]  // Simplified to common key

#[MessageName('notification.sms.sent')]
#[AmqpRoutingKey('notification.sent')]  // Same routing key
```

Both use the same concrete routing key `notification.sent`. Consumers bind with `notification.*` binding key to receive all notification events.

## Migration Guide

### From Custom Exchange Names

**Before:**
```php
// Hardcoded in config
$exchangeName = 'my_custom_exchange';
```

**After:**
```php
#[MessageName('order.placed')]
#[AmqpExchange('my_custom_exchange')]  // Explicit override
final readonly class OrderPlaced { ... }
```

### From Fanout Exchanges

**Before:** All events → single fanout exchange

**After:** Use convention-based topic exchanges with wildcard bindings:

```bash
# Bind queue to multiple exchanges
rabbitmqadmin declare binding source=order.placed destination=my.queue routing_key="#"
rabbitmqadmin declare binding source=user.registered destination=my.queue routing_key="#"
```

## Reference

### Key Distinction

**Publisher (Routing Keys):**
- Set via attributes on event classes
- Must be **concrete strings** (no wildcards)
- Examples: `order.placed`, `user.registered`, `notification.sent`

**Consumer (Binding Keys):**
- Set when binding queues to exchanges in RabbitMQ
- **CAN use wildcards** (`*` and `#`)
- Examples: `order.*`, `*.placed`, `user.#`

### Attributes

| Attribute | Purpose | Example | Wildcards Allowed |
|-----------|---------|---------|-------------------|
| `#[MessageName]` | Define message name (required) | `#[MessageName('order.placed')]` | ❌ No |
| `#[AmqpExchange]` | Override exchange name | `#[AmqpExchange('commerce')]` | ❌ No |
| `#[AmqpRoutingKey]` | Override routing key (publisher) | `#[AmqpRoutingKey('order.event')]` | ❌ No - concrete value only |

### Default Behavior

| Input | Exchange | Routing Key |
|-------|----------|-------------|
| `order.placed` | `order.placed` | `order.placed` |
| `sla.calculation.started` | `sla.calculation` | `sla.calculation.started` |
| `user.account.created` | `user.account` | `user.account.created` |
| `event` | `events` (fallback) | `event` |
