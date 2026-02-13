# Brainstorm: Transport-Agnostic Architecture

**Date:** 2026-02-12
**Status:** Decision captured
**Next:** `/workflows:plan`

---

## What We're Building

Refactoring the message broker from an AMQP-only package into a **transport-agnostic core** with transport-specific plugins. The first plugin will be AMQP (extracted from existing code), with SQS as the next planned transport.

### Requirements

- Same application can publish events to AMQP *and* SQS simultaneously (different events to different transports)
- Domain events must have **zero transport knowledge** — only `#[MessageName]` and `OutboxMessage` on event classes
- AMQP implementation must be **extractable into a separate plugin package** (`freyr/message-broker-amqp`)
- Routing is **convention-based from `#[MessageName]`** — each transport interprets the semantic name according to its own conventions
- Transport-specific routing overrides live in **YAML config**, not in source code attributes
- Sender selection delegates to **Symfony Messenger's native routing** — the core package has no routing logic

---

## Why This Approach

### Current State (Good Foundation)

The codebase already has a clean split:

| Component | AMQP Coupling | Notes |
|-----------|---------------|-------|
| `MessageIdStampMiddleware` | 0% | Transport-agnostic |
| `DeduplicationMiddleware` | 0% | Transport-agnostic, pluggable store |
| `InboxSerializer` / `OutboxSerializer` | 0% | Generic Symfony serialisation |
| `MessageIdStamp` / `MessageNameStamp` | 0% | Pure data stamps |
| `OutboxToAmqpBridge` | **100%** | Creates `AmqpStamp` directly |
| `AmqpExchange` / `AmqpRoutingKey` attributes | **100%** | AMQP routing metadata on event classes |
| `AmqpRoutingStrategyInterface` | **70%** | Methods are AMQP-specific |
| `Amqp/TopologyManager` | **100%** | Infrastructure setup |

**Only three areas need refactoring:** the bridge, the routing strategy, and the attributes.

---

## Chosen Architecture: Core Bridge + Transport Publisher Delegate

### Convention-Based Routing from `#[MessageName]`

`#[MessageName]` is the **single source of routing truth**. Format: `$domain.$subdomain.$action`

Each transport plugin interprets the semantic name according to its own conventions:

| Transport | Message Name | Convention | Example |
|-----------|-------------|------------|---------|
| **AMQP** | `shipment.package.delivered` | Exchange: first segment, routing key: full name | exchange: `shipment`, key: `shipment.package.delivered` |
| **SQS** | `shipment.package.delivered` | Queue derived from domain, message attribute for full name | queue: `shipment`, attr: `shipment.package.delivered` |
| **Kafka** | `shipment.package.delivered` | Topic: first segment, message key: full name | topic: `shipment`, key: `shipment.package.delivered` |

**Overrides** are configured at the **plugin level in YAML**, not in source code:

```yaml
# Plugin-level override config (AMQP example)
message_broker:
  amqp:
    routing:
      'order.placed':
        exchange: commerce           # Override convention
        routing_key: commerce.orders.new  # Override convention
```

### Transport Selection via Symfony Messenger Routing

The core package does **not** decide which transport to use. Symfony Messenger's native routing handles this:

```yaml
# messenger.yaml — Messenger decides which outbox
framework:
  messenger:
    routing:
      'App\Event\OrderPlaced': outbox           # → AMQP outbox
      'App\Event\NotificationSent': outbox_sqs  # → SQS outbox

    transports:
      outbox:      'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
      outbox_sqs:  'doctrine://default?table_name=messenger_outbox&queue_name=outbox_sqs'
      amqp:        'amqp://...'
      sqs:         'sqs://...'
```

Each outbox queue name gets its own `messenger:consume` worker. The core bridge reads the outbox transport name from `ReceivedStamp` and resolves the matching `TransportPublisher`.

### Message Flow

```
Domain Event (with #[MessageName('shipment.package.delivered')])
  -> MessageIdStampMiddleware (core — stamps at dispatch)
  -> Symfony Messenger routing → outbox transport (doctrine://)
  -> messenger:consume outbox
  -> OutboxBridge middleware (core)
     -> Reads ReceivedStamp transport name ('outbox')
     -> Looks up TransportPublisher from service locator
     -> Delegates to AmqpTransportPublisher (plugin)
        -> Reads #[MessageName] → derives exchange + routing key (convention)
        -> Checks YAML overrides
        -> Creates AmqpStamp
        -> Resolves SenderInterface from service locator
        -> Publishes
  -> Short-circuit (no handler for OutboxMessage)
```

### Package Responsibilities

**Core package (`freyr/message-broker`):**

- `OutboxBridge` middleware — reads from outbox, delegates to `TransportPublisher`
- `TransportPublisherInterface` — contract for transport plugins
- `MessageIdStampMiddleware` — stamps at dispatch time
- `DeduplicationMiddleware` + `DeduplicationStore` — inbox deduplication
- `InboxSerializer` / `OutboxSerializer` — semantic name translation
- `MessageIdStamp` / `MessageNameStamp` — pure data stamps
- `#[MessageName]` attribute — semantic name + routing source of truth
- `OutboxMessage` marker interface

**Transport plugin (`freyr/message-broker-amqp`):**

- `AmqpTransportPublisher implements TransportPublisherInterface`
- Convention-based routing: `#[MessageName]` → exchange + routing key
- YAML override config under `message_broker.amqp.routing`
- `TopologyManager`, `AmqpConnectionFactory`, `SetupAmqpTopologyCommand`
- Optional attributes for edge cases (if ever needed — YAML overrides preferred)

---

## Key Decisions

1. **`#[MessageName]` is the routing source of truth** — No transport-specific attributes on domain events. Each transport plugin derives routing from the semantic name by convention. Overrides are in YAML.

2. **Convention-based routing per transport** — AMQP uses first segment as exchange, full name as routing key. SQS/Kafka will define their own conventions. Zero config for standard cases.

3. **Plugin-level YAML overrides** — When convention doesn't fit, configure overrides under the plugin's own config key (`message_broker.amqp.routing`, `message_broker.sqs.routing`). Not in core.

4. **Symfony Messenger routing decides transport** — Different event classes route to different outbox transports via `messenger.yaml`. Core bridge reads the outbox transport name and delegates to the matching publisher.

5. **Core bridge + service locator** — One `OutboxBridge` middleware in core. Transport publishers registered in a service locator keyed by outbox transport name. Plugins register themselves, core resolves.

6. **No routing strategy interface in core** — Routing is entirely the transport plugin's concern. Core only knows about `TransportPublisherInterface`.

7. **AMQP extractable into separate package** — All AMQP-specific code (publisher, routing conventions, topology, config) lives under a clean boundary that can become `freyr/message-broker-amqp`.

---

## TransportPublisherInterface Contract

```php
namespace Freyr\MessageBroker\Outbox;

use Symfony\Component\Messenger\Envelope;

interface TransportPublisherInterface
{
    /**
     * Publish an OutboxMessage to the external transport.
     *
     * The envelope contains:
     * - The OutboxMessage instance
     * - MessageIdStamp (stable across redelivery)
     * - MessageNameStamp (semantic name from #[MessageName])
     *
     * The publisher is responsible for:
     * - Deriving transport-specific routing from the message name
     * - Creating transport-specific stamps (AmqpStamp, SqsStamp, etc.)
     * - Resolving and calling the appropriate SenderInterface
     */
    public function publish(Envelope $envelope, string $messageName): void;
}
```

---

## Resolved Questions

1. **TransportPublisher resolution** — Core bridge reads outbox transport name from `ReceivedStamp`, looks up `TransportPublisher` from service locator keyed by transport name. Each plugin registers against its outbox transport name.

2. **Multi-publisher scenario** — Handled by Symfony Messenger routing. Different events route to different outbox transports, each with its own publisher. Same event to multiple transports = configure multiple routing entries.

3. **Inbox side** — Already transport-agnostic. `InboxSerializer` works with any Symfony Messenger transport. No changes needed for SQS.

## Resolved Questions (continued)

4. **Package naming** — `freyr/message-broker` stays as core. AMQP plugin becomes `freyr/message-broker-amqp`.

5. **Migration path** — Not needed. Package is unreleased, no BC breaks concern. Just refactor all AMQP-related code into `Freyr\MessageBroker\Amqp` namespace — this folder will be extracted to a separate project.

6. **Topology management** — Goes to the AMQP plugin. No need to standardise a core interface — topology is too transport-specific.

---

## Out of Scope (for now)

- Actual SQS implementation (will be a follow-up)
- Kafka support (further out)
- Package split execution (this brainstorm covers interface boundaries, not Composer packaging)
- Topology management abstraction (revisit when SQS needs it)
