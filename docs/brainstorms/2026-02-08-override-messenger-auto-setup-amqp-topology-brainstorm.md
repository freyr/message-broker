---
title: Override Symfony Messenger auto_setup for Custom AMQP Topology
type: research
date: 2026-02-08
status: research-complete
---

# Override Symfony Messenger auto_setup for Custom AMQP Topology

## Context

The Freyr Message Broker uses `auto_setup: false` for AMQP transports (exchanges, queues, bindings managed by ops). This research investigates whether Symfony Messenger's `auto_setup` mechanism can be overridden to inject custom AMQP infrastructure setup (exchanges, queues, bindings) — and whether the same is possible for Doctrine transports.

**Motivation:**
- Full control over RabbitMQ topology (DLX exchanges, DLQ queues, quorum queues, custom bindings)
- Reproducible infrastructure from code rather than manual RabbitMQ management
- Potential integration with `messenger:setup-transports` command

## Research Findings

### 1. How auto_setup Works Internally

The system revolves around `SetupableTransportInterface` — a single `setup(): void` method.

**AMQP Transport** — State-flag lazy setup:
- `Connection` stores `$autoSetupExchange` boolean (default `true`)
- On first `publish()` or `get()`, if flag is `true`, calls `setupExchangeAndQueues()`
- `setupExchangeAndQueues()`: declares exchange → creates exchange-to-exchange bindings → declares queues → binds queues to exchange → sets flag to `false`
- No Symfony events dispatched — plain procedural call

**Doctrine Transport** — Exception-driven lazy setup:
- On first query, catches `TableNotFoundException`
- If `auto_setup: true` and not in active transaction → calls `setup()` → retries via `goto`
- Creates table via Doctrine's schema comparator (`introspectSchema()` vs desired schema)

**Explicit setup** can be triggered via `messenger:setup-transports` console command, which iterates all transports implementing `SetupableTransportInterface`.

### 2. AMQP: What Is Already Configurable (No Override Needed)

The AMQP transport supports extensive topology configuration directly in `messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            my_amqp:
                dsn: 'amqp://...'
                options:
                    exchange:
                        name: commerce
                        type: topic                        # fanout|direct|topic|headers
                        flags: !php/const AMQP_DURABLE
                        arguments:
                            alternate-exchange: unrouted
                        default_publish_routing_key: order.placed
                        bindings:                          # exchange-to-exchange bindings
                            source_exchange:
                                binding_keys: ['order.#']
                                binding_arguments: {}
                    queues:
                        orders_queue:
                            binding_keys: ['order.*']
                            binding_arguments: {}
                            flags: !php/const AMQP_DURABLE
                            arguments:
                                x-dead-letter-exchange: dlx
                                x-dead-letter-routing-key: dlq.orders
                                x-message-ttl: 86400000    # 24h in ms
                                x-max-length: 100000
                                x-max-length-bytes: 104857600
                                x-max-priority: 10
                                x-expires: 604800000       # 7 days in ms
                                x-queue-type: quorum
                                x-delivery-limit: 5
                    delay:
                        exchange_name: delays
                        queue_name_pattern: 'delay_%exchange_name%_%routing_key%_%delay%'
                        arguments:
                            x-queue-type: classic          # quorum has TTL renewal bugs
```

**Gap**: auto_setup **cannot** create the DLX exchange/queue itself — only references them as queue arguments. Cross-transport topology must be managed separately.

### 3. Extension Points for Custom Setup

#### 3a. Transport Decorator (Cleanest for intercepting setup())

Transports are registered as `messenger.transport.<name>` in the DI container:

```php
final class CustomAmqpSetupDecorator implements TransportInterface, SetupableTransportInterface
{
    public function __construct(private TransportInterface $inner) {}

    public function setup(): void
    {
        $this->declareCustomTopology(); // DLX, alternate exchanges, etc.
        if ($this->inner instanceof SetupableTransportInterface) {
            $this->inner->setup();
        }
    }
    // ... delegate get/ack/reject/send to $inner
}
```

```yaml
services:
    App\Transport\CustomAmqpSetupDecorator:
        decorates: 'messenger.transport.amqp_orders'
        arguments: ['@.inner']
```

**Verdict**: Technically sound but fragile — tightly coupled to Symfony's internal service naming.

#### 3b. Custom TransportFactory (For custom DSN scheme)

Register with `messenger.transport_factory` tag. Use a custom DSN scheme (e.g. `amqp-custom://`) so it takes priority. Can inject custom `AmqpFactory` or wrap the standard transport.

```php
final class AmqpWithTopologyTransportFactory implements TransportFactoryInterface
{
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $inner = $this->amqpTransportFactory->createTransport($dsn, $options, $serializer);
        return new AmqpWithTopologyTransport($inner, $this->topologyManager);
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'amqp-custom://');
    }
}
```

**Verdict**: Clean integration with `messenger:setup-transports`, but adds indirection.

#### 3c. Custom Console Command (Most pragmatic for production)

Write a Symfony command using `\AMQPConnection`/`\AMQPExchange`/`\AMQPQueue` directly:

```php
#[AsCommand(name: 'app:setup-rabbitmq')]
final class SetupRabbitMqCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Declare exchanges, DLX, queues with arguments, DLQs, bindings
        // Full control, run in CI/CD or deployment
    }
}
```

**Verdict**: Full control, version-controlled, most teams use this approach in production.

#### 3d. RabbitMQ Definitions JSON (Simplest, no PHP)

```bash
# Export topology
curl -u guest:guest http://localhost:15672/api/definitions > rabbitmq-definitions.json
# Import topology
curl -u guest:guest -X POST -H "Content-Type: application/json" \
  -d @rabbitmq-definitions.json http://localhost:15672/api/definitions
```

**Verdict**: Best for version-controlled infrastructure. Conflicts on immutable objects handled gracefully.

#### 3e. AmqpFactory Injection

The AMQP `Connection` accepts an `AmqpFactory` in its constructor. Could subclass it to customise `\AMQPExchange`/`\AMQPQueue` creation. However, `AmqpTransportFactory` does not expose this — needs a custom transport factory.

#### 3f. No Events for Transport Setup

There are **no Symfony events dispatched during transport setup**. The `setup()` method is purely procedural with no event dispatcher integration. `MessengerPass` compiler pass also has no hooks for customising setup behaviour.

### 4. Doctrine Transport: Schema Override

**Short answer: Not possible through supported APIs.**

- `addTableToSchema()` is **private** in `Connection` (which is `@internal`)
- The `id` column is hardcoded as `BIGINT AUTO_INCREMENT`
- Changing to binary UUID would **break** `lastInsertId()` in `executeInsert()`
- `getExtraSetupSqlForTable()` exists as a hook but currently returns `[]` and is internal

**Options:**
1. `auto_setup: false` + manual migration — **recommended** (current approach for deduplication)
2. Decorate `DoctrineTransport` — run `ALTER TABLE` after `setup()`, but breaks `lastInsertId()`
3. Fully custom transport — implement `TransportInterface` + `SetupableTransportInterface`

**Bottom line**: For outbox/failed tables, BIGINT AUTO_INCREMENT is fine — it is internal transport plumbing. Binary UUIDs only matter for the deduplication table (already managed manually).

### 5. Known Issues and Gotchas

| Issue | Status | Impact |
|-------|--------|--------|
| Quorum queues + delay TTL renewal ([#57867](https://github.com/symfony/symfony/issues/57867)) | **Open** | Delayed messages silently dropped |
| Delay exchange auto-setup cannot be disabled ([#54831](https://github.com/symfony/symfony/issues/54831)) | Closed (won't fix) | Unwanted delay exchange creation |
| Schema drift with `auto_setup: false` ([#61741](https://github.com/symfony/symfony/issues/61741)) | **Open** | Doctrine migrations pick up messenger table |
| Producer creates unwanted queue ([#39652](https://github.com/symfony/symfony/issues/39652)) | Documented | Set `queues: []` to suppress |

**Quorum queue + delay** is critical: Symfony assumes `x-expires` (Queue TTL) renews on re-declaration. Quorum queues do **not** support this. Result: delayed messages silently dropped after TTL expires. Workaround: force `delay.arguments: { x-queue-type: classic }`.

### 6. Notable: Streaming AMQP Transport (2025)

`jwage/phpamqplib-messenger` — a new streaming AMQP transport using `consume()` instead of polling `get()`. Uses `php-amqplib` (no C extension required). DSN: `phpamqplib://`. Worth monitoring as it matures.

## Recommendations for Freyr Message Broker

| Rank | Approach | When to Use |
|------|----------|-------------|
| **1** | Custom console command | Full control, PHP-native, run in CI/CD |
| **2** | RabbitMQ definitions JSON in repo | Zero-code, apply via `rabbitmqadmin` in deployment |
| **3** | Custom `TransportFactory` + `SetupableTransportInterface` | Integrate with `messenger:setup-transports` |
| **4** | Transport decorator | Only if lazy/automatic setup is needed |

**For Doctrine**: Keep current pattern — `auto_setup: true` for outbox/failed (BIGINT fine), manual migration for deduplication (binary UUID v7).

## Key Source Files

| File | Purpose |
|------|---------|
| `symfony/messenger/Transport/SetupableTransportInterface.php` | Core interface (single `setup()` method) |
| `symfony/messenger/Command/SetupTransportsCommand.php` | Console command invoking `setup()` on all transports |
| `symfony/messenger/Transport/TransportFactoryInterface.php` | Factory interface for custom transports |
| `symfony/amqp-messenger/Transport/Connection.php` | Core AMQP class — all setup logic |
| `symfony/amqp-messenger/Transport/AmqpFactory.php` | Factory for low-level AMQP objects |
| `symfony/doctrine-messenger/Transport/Connection.php` | Core Doctrine class — table creation (`@internal`) |
| `symfony/doctrine-bridge/SchemaListener/MessengerTransportDoctrineSchemaListener.php` | Integrates with `doctrine:schema:update` |

## Next Steps

- [ ] Decide which approach to use for AMQP topology management
- [ ] If custom console command: design the topology declaration format (YAML config? PHP attributes?)
- [ ] If custom transport factory: prototype the `AmqpTopologyManager` class
- [ ] Consider whether this should be part of the freyr/message-broker bundle or application-level
