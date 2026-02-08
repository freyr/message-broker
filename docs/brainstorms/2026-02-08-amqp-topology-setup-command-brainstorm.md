---
title: "AMQP Topology Setup Command Design"
type: brainstorm
date: 2026-02-08
status: decisions-made
issue: 15
---

# AMQP Topology Setup Command Design

## Context

Issue #15 requires a console command (`message-broker:setup-amqp`) that declares AMQP infrastructure (exchanges, queues, bindings) from a declarative YAML configuration. Two modes: direct execution against RabbitMQ and `--dump` to export RabbitMQ definitions JSON.

Research: `docs/brainstorms/2026-02-08-override-messenger-auto-setup-amqp-topology-brainstorm.md`

## Decisions

### 1. YAML Configuration Structure

Topology lives under `message_broker.amqp.topology` in the bundle config:

```yaml
message_broker:
    amqp:
        topology:
            exchanges:
                commerce:
                    type: topic           # required: direct|fanout|topic|headers
                    durable: true         # default: true
                    arguments:            # optional
                        alternate-exchange: unrouted

                dlx:
                    type: direct
                    durable: true

                unrouted:
                    type: fanout
                    durable: true

            queues:
                orders_queue:
                    durable: true         # default: true
                    arguments:            # optional
                        x-dead-letter-exchange: dlx
                        x-dead-letter-routing-key: dlq.orders
                        x-queue-type: quorum
                        x-delivery-limit: 5

                dlq.orders:
                    durable: true

                unrouted_queue:
                    durable: true

            bindings:
                - exchange: commerce
                  queue: orders_queue
                  binding_key: 'order.*'

                - exchange: dlx
                  queue: dlq.orders
                  binding_key: 'dlq.orders'

                - exchange: unrouted
                  queue: unrouted_queue
                  # binding_key omitted = '' (empty string, for fanout)
```

**Key naming choice**: `binding_key` (not `routing_key`) — in AMQP, the routing key is set by the publisher; the binding key is the pattern declared on the binding. Our YAML uses the correct term. The mapping to RabbitMQ definitions JSON (`routing_key`) happens in the dump formatter.

### 2. Bindings — Queue-to-Exchange Only (For Now)

Exchange-to-exchange bindings are **deferred** to a follow-up issue. The current scope covers:
- `exchange` + `queue` + optional `binding_key` + optional `arguments`

This simplifies validation and keeps the first implementation focused.

### 3. Architecture — Service + Command

```
SetupAmqpCommand (thin command)
  └── AmqpTopologyManager (service, testable)
        ├── declareTopology(\AMQPChannel): void     # direct execution
        ├── dumpDefinitions(string $vhost): array    # RabbitMQ definitions JSON
        └── dryRun(): array<string>                  # list of planned actions
```

- `AmqpTopologyManager` receives the processed topology config via DI (from `Configuration.php`)
- `SetupAmqpCommand` creates `\AMQPConnection` from DSN, gets channel, delegates to manager
- Separation allows reuse in test bootstrap and programmatic setup

### 4. Exchange Declaration Order — Topological Sort

Automatic dependency resolution:
1. Scan exchange `arguments` for `alternate-exchange` references
2. Scan queue `arguments` for `x-dead-letter-exchange` references
3. Build dependency graph: referenced exchanges must be declared before dependants
4. Declare exchanges in topological order
5. Declare queues (order does not matter — exchanges exist by now)
6. Create bindings last

If a cycle is detected (impossible in practice), throw a clear error.

### 5. AMQP Connection DSN

- Command option: `--dsn=amqp://guest:guest@localhost:5672/%2f`
- Fallback: `%env(MESSENGER_AMQP_DSN)%` environment variable
- No new config key in the bundle — keeps it simple
- DSN parsed via `parse_url()`, connection created with `\AMQPConnection`

### 6. RabbitMQ Definitions JSON Dump (`--dump`)

Output format follows RabbitMQ's native definitions structure:

```json
{
    "exchanges": [
        {
            "name": "commerce",
            "vhost": "/",
            "type": "topic",
            "durable": true,
            "auto_delete": false,
            "internal": false,
            "arguments": {"alternate-exchange": "unrouted"}
        }
    ],
    "queues": [
        {
            "name": "orders_queue",
            "vhost": "/",
            "durable": true,
            "auto_delete": false,
            "arguments": {
                "x-dead-letter-exchange": "dlx",
                "x-dead-letter-routing-key": "dlq.orders",
                "x-queue-type": "quorum",
                "x-delivery-limit": 5
            }
        }
    ],
    "bindings": [
        {
            "source": "commerce",
            "vhost": "/",
            "destination": "orders_queue",
            "destination_type": "queue",
            "routing_key": "order.*",
            "arguments": {}
        }
    ]
}
```

- Vhost extracted from DSN (default: `/`)
- `binding_key` from our YAML maps to `routing_key` in the JSON (RabbitMQ's naming)
- Output to stdout by default, `--output=path` for file
- `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` formatting

### 7. Idempotency & Error Handling

- `declareExchange()` / `declareQueue()` are idempotent if settings match
- On `PRECONDITION_FAILED` (settings mismatch): catch exception, report clearly, continue
- Command returns `SUCCESS` if all declarations succeed, `FAILURE` if any fail
- Each action logged: `[OK] Declared exchange "commerce" (topic, durable)` / `[SKIP] Exchange "commerce" already exists with matching settings`

### 8. Integer Normalisation for Queue Arguments

Queue arguments that must be integers (not strings) for RabbitMQ:
- `x-message-ttl`, `x-max-length`, `x-max-length-bytes`
- `x-max-priority`, `x-expires`, `x-delivery-limit`

The `Configuration.php` validation should enforce integer types for these known keys.

### 9. What is NOT in Scope

- Exchange-to-exchange bindings (follow-up issue)
- Vhost creation (assumed to exist)
- User/permission management
- Policy management
- Deletion of resources not in config (no "reconcile" mode)

## File Structure

```
src/
├── Amqp/
│   ├── TopologyManager.php              # Core service
│   └── DefinitionsFormatter.php         # RabbitMQ JSON formatter
├── Command/
│   └── SetupAmqpTopologyCommand.php     # Console command
└── DependencyInjection/
    └── Configuration.php                # Extended with amqp.topology
```

## Next Steps

- [ ] Create implementation plan (`/workflows:plan`)
- [ ] Create follow-up issue for exchange-to-exchange bindings
