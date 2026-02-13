---
title: "refactor: Extract transport-agnostic core with OutboxPublisherInterface"
type: refactor
date: 2026-02-13
deepened: 2026-02-13
issue: "#24"
brainstorm: docs/brainstorms/2026-02-12-transport-agnostic-architecture-brainstorm.md
---

# refactor: Extract transport-agnostic core with OutboxPublisherInterface

## Enhancement Summary

**Deepened on:** 2026-02-13
**Research agents used:** architecture-strategist, pattern-recognition-specialist, performance-oracle, security-sentinel, code-simplicity-reviewer, best-practices-researcher, learnings-researcher, Context7

### Key Improvements

1. **Naming refined** — `TransportPublisherInterface` renamed to `OutboxPublisherInterface` to avoid conflating with Symfony's "Transport" concept. All names updated consistently.
2. **Compiler pass hardened** — Added duplicate transport name validation, type assertion, and `hasDefinition` guard following `MessengerPass` patterns.
3. **Interface simplified** — `$messageName` removed from `publish()` signature; publisher extracts from `MessageNameStamp` on envelope (single source of truth).
4. **Security hardening** — MessageName format validation, table name quoting in cleanup command.
5. **Performance tuning** — Removed unnecessary `array_filter()`, all outbox path logging changed to `debug`.
6. **Institutional learnings expanded** — Middleware must be both tagged AND explicitly listed in bus config; test config must sync with production.
7. **Stamp namespace consolidation** — `MessageNameStamp` moved to `Stamp/` alongside `MessageIdStamp`.
8. **`OutboxMessage` relocated** — Moved from `Outbox/EventBridge/` to `Outbox/` (EventBridge directory deleted entirely).
9. **`ResolvesFromClass` relocated** — Moved to `Freyr\MessageBroker\Attribute\` shared namespace to avoid cross-boundary dependency.

### New Considerations Discovered

- **ResolvesFromClass trait** relocated to `Freyr\MessageBroker\Attribute\` shared namespace — avoids cross-boundary dependency when AMQP attributes import it
- **Recipe file** (`recipe/1.0/config/packages/messenger.yaml`) must be updated with new middleware class name
- **Bridge activation semantics** changed from explicit transport name check to locator-based check — add `debug` log when publisher not found for diagnostics
- **`AmqpOutboxPublisher` forwards stamps** — uses `$envelope->with(new AmqpStamp(...))` instead of rebuilding envelope from scratch

### Naming Decision

Both the architecture-strategist and pattern-recognition-specialist agents independently recommended the same renaming. "Transport" is overloaded in Symfony Messenger (it already means `TransportInterface`). "Outbox" scopes the interface to its actual purpose.

| Original (plan v1) | Renamed (plan v2) | Rationale |
|---|---|---|
| `TransportPublisherInterface` | `OutboxPublisherInterface` | Avoids conflation with Symfony `TransportInterface` |
| `OutboxBridge` | `OutboxPublishingMiddleware` | Accurate pattern name, consistent with `MessageIdStampMiddleware` and `DeduplicationMiddleware` |
| `AmqpTransportPublisher` | `AmqpOutboxPublisher` | Consistent with interface naming |
| Tag: `message_broker.transport_publisher` | Tag: `message_broker.outbox_publisher` | Removes "transport" redundancy |
| Tag attr: `outbox-transport` | Tag attr: `transport` | Tag already scopes to outbox context |
| `TransportPublisherPass` | `OutboxPublisherPass` | Consistent with tag name |

---

## Overview

Refactor the message broker from an AMQP-only package into a **transport-agnostic core** with transport-specific plugin boundaries. All AMQP-specific code moves under the `Freyr\MessageBroker\Amqp` namespace, preparing for future extraction into a separate `freyr/message-broker-amqp` package.

The package is **unreleased** — no backwards compatibility constraints. All contracts can be reworked freely.

Fixes #24

## Problem Statement

The current codebase tightly couples the outbox bridge, routing strategy, and attributes to AMQP:

- `OutboxToAmqpBridge` directly imports `AmqpStamp` and creates AMQP-specific envelopes
- `AmqpRoutingStrategyInterface` exposes AMQP-specific methods (`getSenderName`, `getRoutingKey`, `getHeaders`)
- `#[AmqpExchange]` and `#[AmqpRoutingKey]` attributes on domain event classes leak transport knowledge
- DI configuration mixes core inbox/outbox services with AMQP topology management
- `composer.json` hard-requires `ext-amqp` and `symfony/amqp-messenger`

Adding SQS or Kafka support would require either duplicating the bridge or heavily conditionalising it.

## Proposed Solution

Introduce an **`OutboxPublisherInterface`** in core and a **generic `OutboxPublishingMiddleware`** that delegates to transport-specific publishers via a service locator. AMQP-specific code moves to `Freyr\MessageBroker\Amqp` namespace with its own DI configuration.

### Design Decisions (from brainstorm)

1. **`#[MessageName]` is the single routing source of truth** — no transport-specific attributes on domain events
2. **Convention-based routing per transport** — AMQP: default sender + full message name as routing key
3. **YAML overrides at plugin level** — `message_broker.amqp.routing` for non-standard routing
4. **Symfony Messenger routing decides transport** — different events route to different outbox transports
5. **Core bridge + service locator** — one `OutboxPublishingMiddleware`, publishers registered by outbox transport name
6. **No routing abstraction in core** — routing is entirely the plugin's concern
7. **AMQP extractable** — clean namespace boundary for future package split

### Critical Design Clarifications

These questions were raised during spec flow analysis and resolved below:

**Exchange-to-sender resolution (Q1):**
Symfony's AMQP transport publishes to the exchange configured in its DSN. You cannot change the exchange per-message via `AmqpStamp`. Therefore:
- **Simple case (most common):** Single AMQP transport with one topic exchange. Convention: routing key = full message name. Consumers bind with patterns (e.g., `order.*`).
- **Multi-exchange case:** Multiple AMQP transports, each with its own exchange in DSN. YAML overrides map message names to sender names. `AmqpOutboxPublisher` has a sender locator + configurable default sender.

**Envelope handling in bridge (Q2):**
Bridge creates a **clean envelope** with only `$event` + `MessageIdStamp` + `MessageNameStamp`. This matches the current `OutboxToAmqpBridge` behaviour (line 86-89) and prevents `ReceivedStamp`/`SentStamp` leaking into the publisher.

**Attribute fate (Q3):**
`#[AmqpExchange]` and `#[AmqpRoutingKey]` move to `Freyr\MessageBroker\Amqp\Routing` namespace. Kept as **optional alternative** to YAML overrides. Domain events in consuming applications should prefer YAML config.

**Multi-outbox support (Q6):**
`OutboxPublishingMiddleware` checks `$publisherLocator->has($transportName)` instead of comparing to a fixed string. One bridge instance handles all outbox transports.

**MessageNameStamp (Q7):**
Bridge adds `MessageNameStamp` to the clean envelope before calling the publisher. This ensures failed transport retries can re-encode with the semantic name.

**Publisher registration (Q4):**
Publishers register via a `message_broker.outbox_publisher` tag with an `transport` attribute. A compiler pass collects tagged publishers into the bridge's service locator.

## Technical Approach

### Architecture

```
POST-REFACTORING:

src/
├── Attribute/                              # CORE (shared utilities)
│   └── ResolvesFromClass.php              # moved from Outbox/ (avoids cross-boundary dep)
├── Outbox/                                 # CORE (transport-agnostic)
│   ├── OutboxPublisherInterface.php       # NEW — contract for plugins
│   ├── OutboxPublishingMiddleware.php     # NEW — generic bridge middleware
│   ├── OutboxMessage.php                  # moved from EventBridge/ (marker interface)
│   ├── MessageIdStampMiddleware.php       # unchanged
│   └── MessageName.php                    # unchanged
├── Inbox/                                  # CORE (unchanged)
│   ├── DeduplicationMiddleware.php
│   ├── DeduplicationDbalStore.php
│   └── DeduplicationStore.php
├── Stamp/                                  # CORE (all stamps together)
│   ├── MessageIdStamp.php
│   └── MessageNameStamp.php               # moved from Serializer/
├── Serializer/                             # CORE (serialisers only)
│   ├── InboxSerializer.php
│   ├── OutboxSerializer.php
│   └── Normalizer/
├── Amqp/                                   # PLUGIN (extractable to freyr/message-broker-amqp)
│   ├── AmqpOutboxPublisher.php          # NEW — implements OutboxPublisherInterface
│   ├── Routing/
│   │   ├── AmqpRoutingStrategyInterface.php  # moved from Outbox/Routing/
│   │   ├── DefaultAmqpRoutingStrategy.php    # moved from Outbox/Routing/
│   │   ├── AmqpExchange.php                  # moved from Outbox/Routing/
│   │   └── AmqpRoutingKey.php                # moved from Outbox/Routing/
│   ├── TopologyManager.php                 # stays
│   ├── DefinitionsFormatter.php            # stays
│   ├── AmqpConnectionFactory.php           # stays
│   └── DependencyInjection/                # NEW — plugin-level DI
│       └── AmqpConfiguration.php           # extracted from core Configuration
├── Command/
│   ├── SetupAmqpTopologyCommand.php        # moves to Amqp/Command/ (AMQP-specific)
│   └── DeduplicationStoreCleanup.php       # stays (core)
├── DependencyInjection/                    # CORE — stripped of AMQP config
│   ├── Configuration.php                   # inbox only + publisher tag collection
│   ├── FreyrMessageBrokerExtension.php     # core services only
│   └── Compiler/
│       └── OutboxPublisherPass.php      # NEW — collects tagged publishers
├── Doctrine/                               # CORE (unchanged)
└── FreyrMessageBrokerBundle.php            # registers compiler pass
```

### New Core Interfaces

#### `OutboxPublisherInterface`

```php
// src/Outbox/OutboxPublisherInterface.php
namespace Freyr\MessageBroker\Outbox;

use Symfony\Component\Messenger\Envelope;

/**
 * Publish an OutboxMessage to an external transport.
 *
 * The envelope is the single source of truth. It contains:
 * - The OutboxMessage instance (unwrapped event)
 * - MessageIdStamp (stable UUID v7, survives redelivery)
 * - MessageNameStamp (semantic name from #[MessageName])
 *
 * Implementations are responsible for:
 * - Extracting MessageNameStamp to derive transport-specific routing
 * - Creating transport-specific stamps (AmqpStamp, SqsStamp, etc.)
 * - Resolving and calling the appropriate SenderInterface
 */
interface OutboxPublisherInterface
{
    public function publish(Envelope $envelope): void;
}
```

#### `OutboxPublishingMiddleware` (middleware)

```php
// src/Outbox/OutboxPublishingMiddleware.php
namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Outbox\OutboxMessage;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Stamp\MessageNameStamp;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final readonly class OutboxPublishingMiddleware implements MiddlewareInterface
{
    /**
     * @param ContainerInterface $publisherLocator Keyed by outbox transport name
     */
    public function __construct(
        private ContainerInterface $publisherLocator,
        private LoggerInterface $logger,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only process OutboxMessage consumed from an outbox transport
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        $receivedStamp = $envelope->last(ReceivedStamp::class);
        if (!$receivedStamp instanceof ReceivedStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $transportName = $receivedStamp->getTransportName();

        // Only handle transports with a registered publisher
        if (!$this->publisherLocator->has($transportName)) {
            $this->logger->debug('No outbox publisher registered for transport, passing through.', [
                'transport' => $transportName,
            ]);

            return $stack->next()->handle($envelope, $stack);
        }

        $event = $envelope->getMessage();

        $messageName = MessageName::fromClass($event)
            ?? throw new RuntimeException(sprintf(
                'Event %s must have #[MessageName] attribute.',
                $event::class,
            ));

        $messageIdStamp = $envelope->last(MessageIdStamp::class)
            ?? throw new RuntimeException(sprintf(
                'OutboxMessage %s consumed without MessageIdStamp. '
                . 'Ensure MessageIdStampMiddleware runs at dispatch time.',
                $event::class,
            ));

        // Build clean envelope for publisher (strip transport stamps)
        $publishEnvelope = new Envelope($event, [
            $messageIdStamp,
            new MessageNameStamp($messageName),
        ]);

        $this->logger->debug('Delegating outbox event to transport publisher', [
            'message_name' => $messageName,
            'message_id' => $messageIdStamp->messageId,
            'event_class' => $event::class,
            'outbox_transport' => $transportName,
        ]);

        /** @var OutboxPublisherInterface $publisher */
        $publisher = $this->publisherLocator->get($transportName);
        $publisher->publish($publishEnvelope);

        // Short-circuit: no handler for OutboxMessage
        return $envelope;
    }
}
```

### AMQP Plugin Implementation

#### `AmqpOutboxPublisher`

```php
// src/Amqp/AmqpOutboxPublisher.php
namespace Freyr\MessageBroker\Amqp;

use Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface;
use Freyr\MessageBroker\Outbox\OutboxPublisherInterface;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Stamp\MessageNameStamp;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final readonly class AmqpOutboxPublisher implements OutboxPublisherInterface
{
    /**
     * @param ContainerInterface $senderLocator Keyed by sender/transport name (e.g. 'amqp', 'commerce')
     */
    public function __construct(
        private ContainerInterface $senderLocator,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private LoggerInterface $logger,
    ) {}

    public function publish(Envelope $envelope): void
    {
        $event = $envelope->getMessage();

        $messageName = $envelope->last(MessageNameStamp::class)?->messageName
            ?? throw new RuntimeException(sprintf(
                'Envelope for %s missing MessageNameStamp. '
                . 'Ensure OutboxPublishingMiddleware runs before the publisher.',
                $event::class,
            ));

        $messageIdStamp = $envelope->last(MessageIdStamp::class)
            ?? throw new RuntimeException(sprintf(
                'Envelope for %s missing MessageIdStamp.',
                $event::class,
            ));

        $senderName = $this->routingStrategy->getSenderName($event, $messageName);

        if (!$this->senderLocator->has($senderName)) {
            throw new RuntimeException(sprintf(
                'No AMQP sender "%s" configured for %s. '
                . 'Register the transport in the AmqpOutboxPublisher sender locator.',
                $senderName,
                $event::class,
            ));
        }

        $routingKey = $this->routingStrategy->getRoutingKey($event, $messageName);
        $headers = $this->routingStrategy->getHeaders($messageName);

        // Forward all stamps from bridge envelope, add AMQP-specific stamp
        $amqpEnvelope = $envelope->with(
            new AmqpStamp($routingKey, AMQP_NOPARAM, $headers),
        );

        $this->logger->debug('Publishing event to AMQP', [
            'message_name' => $messageName,
            'message_id' => $messageIdStamp->messageId,
            'event_class' => $event::class,
            'sender' => $senderName,
            'routing_key' => $routingKey,
        ]);

        /** @var SenderInterface $sender */
        $sender = $this->senderLocator->get($senderName);
        $sender->send($amqpEnvelope);
    }
}
```

#### Updated `AmqpRoutingStrategyInterface`

```php
// src/Amqp/Routing/AmqpRoutingStrategyInterface.php
namespace Freyr\MessageBroker\Amqp\Routing;

interface AmqpRoutingStrategyInterface
{
    /**
     * Resolve the sender name (Symfony Messenger transport) for publishing.
     * The returned name must match a key in the AmqpOutboxPublisher sender locator.
     */
    public function getSenderName(object $event, string $messageName): string;

    /**
     * Resolve the AMQP routing key for the message.
     */
    public function getRoutingKey(object $event, string $messageName): string;

    /**
     * Resolve AMQP message headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(string $messageName): array;
}
```

#### Updated `DefaultAmqpRoutingStrategy`

```php
// src/Amqp/Routing/DefaultAmqpRoutingStrategy.php
namespace Freyr\MessageBroker\Amqp\Routing;

/**
 * Convention-based AMQP routing from #[MessageName].
 *
 * Default behaviour:
 * - Sender: configurable default (e.g. 'amqp')
 * - Routing key: full message name (e.g. 'order.placed')
 * - Headers: x-message-name header
 *
 * Override via YAML config or #[AmqpExchange]/#[AmqpRoutingKey] attributes.
 * YAML overrides take precedence over attributes.
 */
final readonly class DefaultAmqpRoutingStrategy implements AmqpRoutingStrategyInterface
{
    /**
     * @param array<string, array{sender?: string, routing_key?: string}> $routingOverrides
     */
    public function __construct(
        private string $defaultSenderName = 'amqp',
        private array $routingOverrides = [],
    ) {}

    public function getSenderName(object $event, string $messageName): string
    {
        // 1. YAML override
        if (isset($this->routingOverrides[$messageName]['sender'])) {
            return $this->routingOverrides[$messageName]['sender'];
        }

        // 2. Attribute override (optional)
        $attributeSender = AmqpExchange::fromClass($event);
        if ($attributeSender !== null) {
            return $attributeSender;
        }

        // 3. Default sender
        return $this->defaultSenderName;
    }

    public function getRoutingKey(object $event, string $messageName): string
    {
        // 1. YAML override
        if (isset($this->routingOverrides[$messageName]['routing_key'])) {
            return $this->routingOverrides[$messageName]['routing_key'];
        }

        // 2. Attribute override (optional)
        $attributeKey = AmqpRoutingKey::fromClass($event);
        if ($attributeKey !== null) {
            return $attributeKey;
        }

        // 3. Convention: full message name as routing key
        return $messageName;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(string $messageName): array
    {
        return [
            'x-message-name' => $messageName,
        ];
    }
}
```

### Service Locator Wiring

#### Compiler Pass (auto-registration)

```php
// src/DependencyInjection/Compiler/OutboxPublisherPass.php
namespace Freyr\MessageBroker\DependencyInjection\Compiler;

use Freyr\MessageBroker\Outbox\OutboxPublisherInterface;
use Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class OutboxPublisherPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(OutboxPublishingMiddleware::class)) {
            return;
        }

        $publishers = [];
        foreach ($container->findTaggedServiceIds('message_broker.outbox_publisher') as $id => $tags) {
            // Validate the tagged service implements the interface
            $definition = $container->getDefinition($id);
            $class = $definition->getClass();
            if ($class !== null && !is_subclass_of($class, OutboxPublisherInterface::class)) {
                throw new \InvalidArgumentException(sprintf(
                    'Service "%s" tagged with "message_broker.outbox_publisher" must implement %s.',
                    $id,
                    OutboxPublisherInterface::class,
                ));
            }

            foreach ($tags as $tag) {
                $transportName = $tag['transport']
                    ?? throw new \InvalidArgumentException(sprintf(
                        'Service "%s" tagged with "message_broker.outbox_publisher" must define "transport" attribute.',
                        $id,
                    ));

                // Prevent duplicate transport name registration
                if (isset($publishers[$transportName])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Duplicate outbox publisher for transport "%s": services "%s" and "%s" both claim it.',
                        $transportName,
                        (string) $publishers[$transportName],
                        $id,
                    ));
                }

                $publishers[$transportName] = new Reference($id);
            }
        }

        if ($publishers === []) {
            $container->log($this, 'No outbox publishers registered. OutboxPublishingMiddleware will not publish any messages.');
        }

        $bridge = $container->getDefinition(OutboxPublishingMiddleware::class);
        $bridge->setArgument('$publisherLocator', ServiceLocatorTagPass::register($container, $publishers));
    }
}
```

### Research Insights: Compiler Pass

**Best Practices (from Symfony MessengerPass, Notifier, Context7):**
- The `ServiceLocatorTagPass::register()` pattern is canonical — used by MessengerPass for failure transports and sender locators
- Always guard with `hasDefinition()` before `getDefinition()` (defensive pattern from Symfony's own passes)
- Duplicate detection prevents silent overwrites — a common misconfiguration source
- Type assertion catches mistagged services at compile time, not runtime

**Note:** With SQS planned alongside AMQP, the compiler pass is justified from day one — it handles auto-registration of multiple publishers without manual YAML wiring per transport.

#### AMQP Plugin Service Registration

```yaml
# config/services.yaml (AMQP section — stays in same file for now)

    # AMQP Transport Publisher
    Freyr\MessageBroker\Amqp\AmqpOutboxPublisher:
        arguments:
            $senderLocator: !service_locator
                amqp: '@messenger.transport.amqp'
            $routingStrategy: '@Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface'
            $logger: '@logger'
        tags:
            - { name: 'message_broker.outbox_publisher', transport: 'outbox' }

    # AMQP Routing Strategy
    Freyr\MessageBroker\Amqp\Routing\AmqpRoutingStrategyInterface:
        class: Freyr\MessageBroker\Amqp\Routing\DefaultAmqpRoutingStrategy
        arguments:
            $defaultSenderName: 'amqp'
            $routingOverrides: '%message_broker.amqp.routing_overrides%'
```

### YAML Routing Override Configuration

```php
// In Configuration.php, under amqp section:
->arrayNode('routing')
    ->info('Override convention-based routing for specific message names')
    ->useAttributeAsKey('message_name')
    ->defaultValue([])
    ->arrayPrototype()
        ->children()
            ->scalarNode('sender')
                ->info('Override sender/transport name (default: amqp)')
                ->defaultNull()
            ->end()
            ->scalarNode('routing_key')
                ->info('Override AMQP routing key (default: full message name)')
                ->defaultNull()
            ->end()
        ->end()
    ->end()
->end()
```

Usage:

```yaml
# Application config
message_broker:
    amqp:
        routing:
            'order.placed':
                sender: commerce        # Publish via 'commerce' transport
                routing_key: commerce.orders.new  # Custom routing key
```

### Message Flow (Post-Refactoring)

```
DISPATCH:
  Domain Event (with #[MessageName('shipment.package.delivered')])
    -> MessageIdStampMiddleware (core — adds MessageIdStamp with UUID v7)
    -> Symfony Messenger routing → 'outbox' transport (doctrine://)
    -> Stored in messenger_outbox table (within business transaction)

CONSUME:
  messenger:consume outbox
    -> Doctrine transport reads from messenger_outbox
    -> Bus re-dispatches with ReceivedStamp('outbox')
    -> MessageIdStampMiddleware (skips — ReceivedStamp present)
    -> OutboxPublishingMiddleware (core)
       -> Checks: OutboxMessage? ReceivedStamp? Publisher registered for 'outbox'?
       -> Extracts #[MessageName] → 'shipment.package.delivered'
       -> Reads MessageIdStamp from envelope
       -> Builds clean envelope: event + MessageIdStamp + MessageNameStamp
       -> Resolves publisher from locator: 'outbox' → AmqpOutboxPublisher
       -> Calls publisher->publish(cleanEnvelope)
    -> AmqpOutboxPublisher (plugin)
       -> Extracts MessageNameStamp → 'shipment.package.delivered'
       -> Routing strategy: sender='amqp', routing_key='shipment.package.delivered'
       -> Forwards envelope stamps + adds AmqpStamp with routing key + headers
       -> Resolves SenderInterface from sender locator: 'amqp'
       -> Calls sender->send(amqpEnvelope)
    -> Short-circuit (returns original envelope)
```

### Research Insights: Performance

**All impacts rated negligible by the performance-oracle agent:**
- **Middleware overhead:** Third guard clause (`publisherLocator->has()`) is an `isset()` on a PHP array — O(1), ~50ns
- **Nested service locators:** Two locator resolutions per message after warm-up cost ~200-400ns total. Compare to DB read (~1-5ms) + AMQP publish (~0.5-2ms). Overhead is <0.01% of total processing time.
- **Clean envelope creation:** One extra `Envelope` + `MessageNameStamp` per message ~300-500ns, immediately GC'd
- **Routing strategy reflection:** Cached via `ResolvesFromClass` static cache — reflected once per message class, then O(1)
- **Memory:** ~200 bytes additional resident memory for the extra service singleton

**Recommendations applied:**
1. Removed `array_filter()` from `AmqpOutboxPublisher` envelope creation (bridge guarantees non-null stamps)
2. Changed bridge log level from `info` to `debug` (reduces log volume by 50% on outbox path)

### Research Insights: Security

**Findings from security-sentinel agent (no critical vulnerabilities):**

| # | Finding | Severity | Action |
|---|---------|----------|--------|
| 1 | No validation on `#[MessageName]` format | **MEDIUM** | Add regex validation in constructor (see below) |
| 2 | AMQP header injection via message name | **NONE** | AMQP 0-9-1 uses typed field tables, not flat strings |
| 3 | Clean envelope strips all stamps | **LOW** | Document in interface contract |
| 4 | Service locator trust boundary | **LOW** | Type assertion added in compiler pass |
| 5 | SQL table name interpolation in `DeduplicationStoreCleanup` | **MEDIUM** | Use `$connection->quoteIdentifier()` (pre-existing, separate fix) |

**MessageName format validation (Priority 1 — add during implementation):**

```php
// src/Outbox/MessageName.php — add to constructor
public function __construct(
    public readonly string $name,
) {
    if ($name === '' || !preg_match('/\A[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+\z/', $name)) {
        throw new \InvalidArgumentException(sprintf(
            'MessageName must match pattern "segment.segment.segment" (lowercase alphanumeric, dot-separated). Got: "%s"',
            $name,
        ));
    }
}
```

This enforces the `{domain}.{subdomain}.{action}` convention at compile time and prevents routing issues with future transport plugins (SQS topic names, Kafka topic names).

### Research Insights: Cross-Transport Routing Conventions

**From best-practices-researcher (Ecotone, Laravel, php-enqueue, Kafka/RabbitMQ docs):**

The `#[MessageName]` convention (`order.placed`) maps cleanly across all transports:

| Message Name | RabbitMQ | SNS/SQS | Kafka |
|---|---|---|---|
| `order.placed` | routing_key: `order.placed` | topic: `order-placed` | topic: `order.placed` |
| `sla.calc.started` | routing_key: `sla.calc.started` | topic: `sla-calc-started` | topic: `sla.calc.started` |

Each transport plugin interprets the semantic name according to its own conventions — the core never needs to know about transport-specific naming rules.

### Composer Dependency Changes

```json
// composer.json changes:
{
    "require": {
        // REMOVE: "ext-amqp" and "symfony/amqp-messenger"
        // These are AMQP-plugin concerns, not core
    },
    "require-dev": {
        // ADD: "ext-amqp" (needed for tests)
        // ADD: "symfony/amqp-messenger" (needed for tests)
    },
    "suggest": {
        "ext-amqp": "Required for AMQP transport support",
        "symfony/amqp-messenger": "Required for AMQP transport support"
    }
}
```

## Implementation Phases

### Phase 1: Core Abstraction + Namespace Moves (foundation) ✅

Create the transport-agnostic core interfaces, middleware, and consolidate namespaces.

- [x] Move `src/Outbox/EventBridge/OutboxMessage.php` → `src/Outbox/OutboxMessage.php`
- [x] Move `src/Outbox/ResolvesFromClass.php` → `src/Attribute/ResolvesFromClass.php`
- [x] Move `src/Serializer/MessageNameStamp.php` → `src/Stamp/MessageNameStamp.php`
- [x] Update all imports for the three moved files across `src/` and `tests/`
- [x] Add `#[MessageName]` format validation (regex: `/\A[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+\z/`) — security hardening
- [x] Create `src/Outbox/OutboxPublisherInterface.php`
- [x] Create `src/Outbox/OutboxPublishingMiddleware.php` middleware
- [x] Create `src/DependencyInjection/Compiler/OutboxPublisherPass.php` (with duplicate detection + type assertion)
- [x] Register compiler pass in `FreyrMessageBrokerBundle.php`
- [x] Write unit tests for `OutboxPublishingMiddleware`:
  - [x] `tests/Unit/Outbox/OutboxPublishingMiddlewareTest.php` — pass-through for non-OutboxMessage
  - [x] Pass-through when no ReceivedStamp
  - [x] Pass-through when publisher not registered for transport (verify debug log)
  - [x] Successful delegation to publisher
  - [x] RuntimeException when MessageName missing
  - [x] RuntimeException when MessageIdStamp missing
  - [x] Verifies clean envelope (only MessageIdStamp + MessageNameStamp)
  - [x] Verifies short-circuit (does not call $stack->next())
- [x] Write unit tests for `OutboxPublisherPass`:
  - [x] Collects tagged publishers into service locator
  - [x] Throws on missing `transport` attribute
  - [x] Throws on duplicate transport name
  - [x] Throws on service not implementing `OutboxPublisherInterface`
  - [x] Logs warning when no publishers registered
  - [x] Early return when `OutboxPublishingMiddleware` not defined

### Phase 2: AMQP Plugin Implementation ✅

Create `AmqpOutboxPublisher` by extracting logic from `OutboxToAmqpBridge`. Move AMQP routing classes.

- [x] Create `src/Amqp/AmqpOutboxPublisher.php`
- [x] Move `src/Outbox/Routing/AmqpRoutingStrategyInterface.php` → `src/Amqp/Routing/AmqpRoutingStrategyInterface.php`
- [x] Move `src/Outbox/Routing/DefaultAmqpRoutingStrategy.php` → `src/Amqp/Routing/DefaultAmqpRoutingStrategy.php`
- [x] Move `src/Outbox/Routing/AmqpExchange.php` → `src/Amqp/Routing/AmqpExchange.php`
- [x] Move `src/Outbox/Routing/AmqpRoutingKey.php` → `src/Amqp/Routing/AmqpRoutingKey.php`
- [x] Update `DefaultAmqpRoutingStrategy` — add `$messageName` to `getSenderName()`, add `$routingOverrides` constructor parameter
- [x] Move `src/Command/SetupAmqpTopologyCommand.php` → `src/Amqp/Command/SetupAmqpTopologyCommand.php`
- [x] Write unit tests for `AmqpOutboxPublisher`:
  - [x] `tests/Unit/Amqp/AmqpOutboxPublisherTest.php` — convention routing
  - [x] YAML override routing
  - [x] Attribute override routing
  - [x] RuntimeException when sender not in locator
  - [x] RuntimeException when MessageNameStamp missing
  - [x] Verifies AmqpStamp created with correct routing key + headers
  - [x] Verifies stamps forwarded from bridge envelope (not rebuilt)
- [x] Move `tests/Unit/Routing/` → `tests/Unit/Amqp/Routing/`
- [x] Update `DefaultAmqpRoutingStrategyTest` for new constructor signature and YAML overrides

### Phase 3: Configuration + Test Migration (atomic — same commit) ✅

Split DI configuration and update all tests together. **Critical learning: config and test config must be updated atomically.**

- [x] Update `src/DependencyInjection/Configuration.php` — add `amqp.routing` override section alongside existing `amqp.topology`
- [x] Update `src/DependencyInjection/FreyrMessageBrokerExtension.php` — set `message_broker.amqp.routing_overrides` parameter
- [x] Update `config/services.yaml`:
  - [x] Replace `OutboxToAmqpBridge` with `OutboxPublishingMiddleware` (core)
  - [x] Add `AmqpOutboxPublisher` with tag `message_broker.outbox_publisher`
  - [x] Update namespace references for moved routing classes
  - [x] Update namespace for `SetupAmqpTopologyCommand`
- [x] Update `tests/Functional/config/test.yaml` — **same commit as services.yaml**:
  - [x] Replace `OutboxToAmqpBridge` with `OutboxPublishingMiddleware` in middleware chain
  - [x] Add `AmqpOutboxPublisher` service with tag
  - [x] Update routing strategy namespace
  - [x] Update command namespace
- [x] Update `tests/Unit/Factory/EventBusFactory.php` (done in Phase 2)
- [x] Update `composer.json` — move `ext-amqp` and `symfony/amqp-messenger` to `require-dev` + `suggest`
- [x] Update `recipe/1.0/config/packages/messenger.yaml` — replace `OutboxToAmqpBridge` with `OutboxPublishingMiddleware`
- [x] Run full test suite — 108 tests pass
- [x] Run PHPStan — clean, no errors

### Phase 4: Cleanup and Delete ✅

Remove old files after all tests pass.

- [x] Delete `src/Outbox/EventBridge/OutboxToAmqpBridge.php`
- [x] Delete `src/Outbox/EventBridge/OutboxMessage.php` (duplicate — moved to `Outbox/` in Phase 1)
- [x] Delete `src/Outbox/EventBridge/` directory
- [x] Delete `src/Outbox/Routing/` files (moved in Phase 2)
- [x] Verify no stale references in `src/` and `tests/`

### Phase 5: Documentation ✅

Update all documentation to reflect the new architecture.

- [x] Update `README.md`:
  - [x] Service configuration examples
  - [x] AMQP routing override examples (YAML + attributes)
  - [x] Manual installation middleware chain
  - [x] OutboxMessage import namespace
- [x] Update `docs/amqp-routing.md` — reflect YAML overrides, override precedence, and new class locations
- [x] Verify recipe/config examples reference correct class names

## Affected Files

### New Files

| File | Purpose |
|------|---------|
| `src/Outbox/OutboxPublisherInterface.php` | Core contract for transport plugins |
| `src/Outbox/OutboxPublishingMiddleware.php` | Core bridge middleware |
| `src/Amqp/AmqpOutboxPublisher.php` | AMQP implementation of publisher |
| `src/DependencyInjection/Compiler/OutboxPublisherPass.php` | Compiler pass for publisher locator (with duplicate detection + type assertion) |
| `src/Amqp/Command/SetupAmqpTopologyCommand.php` | Moved from `src/Command/` |
| `tests/Unit/Outbox/OutboxPublishingMiddlewareTest.php` | Tests for core bridge |
| `tests/Unit/Outbox/OutboxPublisherPassTest.php` | Tests for compiler pass |
| `tests/Unit/Amqp/AmqpOutboxPublisherTest.php` | Tests for AMQP publisher |

### Moved Files (namespace change)

| From | To |
|------|----|
| `src/Outbox/Routing/AmqpRoutingStrategyInterface.php` | `src/Amqp/Routing/AmqpRoutingStrategyInterface.php` |
| `src/Outbox/Routing/DefaultAmqpRoutingStrategy.php` | `src/Amqp/Routing/DefaultAmqpRoutingStrategy.php` |
| `src/Outbox/Routing/AmqpExchange.php` | `src/Amqp/Routing/AmqpExchange.php` |
| `src/Outbox/Routing/AmqpRoutingKey.php` | `src/Amqp/Routing/AmqpRoutingKey.php` |
| `src/Command/SetupAmqpTopologyCommand.php` | `src/Amqp/Command/SetupAmqpTopologyCommand.php` |
| `src/Outbox/EventBridge/OutboxMessage.php` | `src/Outbox/OutboxMessage.php` |
| `src/Outbox/ResolvesFromClass.php` | `src/Attribute/ResolvesFromClass.php` |
| `src/Serializer/MessageNameStamp.php` | `src/Stamp/MessageNameStamp.php` |

### Modified Files

| File | Changes |
|------|---------|
| `config/services.yaml` | Replace OutboxToAmqpBridge with OutboxPublishingMiddleware + AmqpOutboxPublisher |
| `src/DependencyInjection/Configuration.php` | Add `amqp.routing` override config |
| `src/DependencyInjection/FreyrMessageBrokerExtension.php` | Set routing override parameter |
| `src/FreyrMessageBrokerBundle.php` | Register `OutboxPublisherPass` |
| `composer.json` | Move AMQP deps to require-dev/suggest |
| `tests/Unit/Factory/EventBusFactory.php` | Update middleware wiring |
| `tests/Unit/Fixtures/CommerceTestMessage.php` | Update import namespaces |
| `tests/Unit/Routing/DefaultAmqpRoutingStrategyTest.php` | Move + update |
| `tests/Unit/Routing/AmqpExchangeTest.php` | Move + update |
| `tests/Functional/config/test.yaml` | Update service references |
| `README.md` | Architecture, config examples |
| `CLAUDE.md` | Directory structure, patterns |
| `docs/amqp-routing.md` | Class locations, YAML overrides |
| `recipe/1.0/config/packages/messenger.yaml` | Update middleware class reference |
| `src/Outbox/MessageName.php` | Add format validation regex, update `ResolvesFromClass` import |
| `src/Serializer/OutboxSerializer.php` | Update `MessageNameStamp` import to `Stamp\` namespace |
| `src/Serializer/InboxSerializer.php` | Update `MessageNameStamp` import to `Stamp\` namespace |

### Deleted Files

| File | Reason |
|------|--------|
| `src/Outbox/EventBridge/OutboxToAmqpBridge.php` | Replaced by OutboxPublishingMiddleware + AmqpOutboxPublisher |
| `src/Outbox/EventBridge/` | Directory removed (OutboxMessage moved to `Outbox/`) |
| `src/Outbox/Routing/AmqpRoutingStrategyInterface.php` | Moved to `Amqp/Routing/` |
| `src/Outbox/Routing/DefaultAmqpRoutingStrategy.php` | Moved to `Amqp/Routing/` |
| `src/Outbox/Routing/AmqpExchange.php` | Moved to `Amqp/Routing/` |
| `src/Outbox/Routing/AmqpRoutingKey.php` | Moved to `Amqp/Routing/` |
| `src/Outbox/Routing/` | Directory removed |
| `tests/Unit/OutboxToAmqpBridgeTest.php` | Replaced by new tests |

## Acceptance Criteria

### Functional Requirements

- [ ] `OutboxPublishingMiddleware` middleware delegates to the correct `OutboxPublisherInterface` based on outbox transport name
- [ ] `AmqpOutboxPublisher` creates `AmqpStamp` with correct routing key and headers
- [ ] Convention-based routing: default sender + full message name as routing key
- [ ] YAML override routing: sender and/or routing key overridable per message name
- [ ] Attribute override routing: `#[AmqpExchange]` and `#[AmqpRoutingKey]` still work (from new namespace)
- [ ] Multi-outbox support: different outbox transports resolve different publishers
- [ ] Clean envelope: publisher receives only event + MessageIdStamp + MessageNameStamp
- [ ] Short-circuit: bridge does not call `$stack->next()` after publishing
- [ ] Pass-through: non-OutboxMessage envelopes flow through unchanged
- [ ] Error handling: clear RuntimeException for missing MessageName, MessageIdStamp, or publisher

### Non-Functional Requirements

- [ ] All AMQP-specific code resides under `Freyr\MessageBroker\Amqp` namespace
- [ ] No AMQP imports in any core file (`src/Outbox/`, `src/Inbox/`, `src/Serializer/`, `src/Stamp/`)
- [ ] `ext-amqp` and `symfony/amqp-messenger` not in `require` (only `require-dev`)
- [ ] All 92+ existing tests pass (updated for new architecture)
- [ ] PHPStan passes with no errors
- [ ] ECS passes with no errors

### Quality Gates

- [ ] Unit test coverage for `OutboxPublishingMiddleware` (8+ test cases)
- [ ] Unit test coverage for `AmqpOutboxPublisher` (5+ test cases)
- [ ] Functional tests pass end-to-end (outbox → AMQP → inbox)
- [ ] `grep -r 'AmqpStamp\|AmqpExchange\|AmqpRouting' src/Outbox/ src/Inbox/ src/Serializer/` returns no results

## Risk Analysis

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Middleware ordering breaks transactional guarantees | Low | High | Test middleware chain explicitly; keep same position as OutboxToAmqpBridge |
| Stamp serialisation breaks with namespace changes | Low | Medium | MessageIdStamp stays in same namespace; test round-trip serialisation |
| Compiler pass fails silently (no publishers registered) | Medium | Medium | **RESOLVED:** Added `$container->log()` warning when `$publishers === []` |
| Test config diverges from production config | Medium | High | Learning #1: explicit middleware in bus config, not just tags |
| Doctrine ORM config breaks in test environment | Low | High | Learning #2: verify `enable_native_lazy_objects: true` in test config |
| Duplicate transport name in tag registration | Low | High | **RESOLVED:** Added duplicate detection in `OutboxPublisherPass` |
| `ResolvesFromClass` trait cross-boundary dependency | Low | Low | **RESOLVED:** Moved to `Freyr\MessageBroker\Attribute\ResolvesFromClass` — shared namespace avoids cross-boundary import |
| Recipe file references stale class name | Medium | Medium | **Must update** `recipe/1.0/config/packages/messenger.yaml` alongside code changes |
| Bridge activation semantics change (locator-based vs explicit name) | Low | High | **RESOLVED:** Added `debug` log when publisher not found for diagnostics |
| Unvalidated `#[MessageName]` values cause routing issues | Low | Medium | **Add regex validation** in `MessageName` constructor during Phase 1 |

## Learnings Applied

From `docs/solutions/` — all 7 solution files were reviewed by the learnings-researcher agent:

### Critical (must follow)

1. **Middleware must be both tagged AND explicitly listed in bus config** — `OutboxPublishingMiddleware` must appear in the `middleware:` list under `messenger.bus.default`. Tagging with `messenger.middleware` alone is NOT sufficient (from `deduplication-middleware-not-running-in-tests.md`). This was the root cause of a previous production issue where deduplication silently stopped working.

2. **Test config must sync with production config** — When updating `config/services.yaml`, ALSO update `tests/Functional/config/test.yaml` in the same commit. Keep the bus middleware list identical. If a functional test passes after removing a middleware reference, that is a false positive — the middleware is simply not running (from `deduplication-middleware-not-running-in-tests.md`).

3. **Schema setup in bootstrap, NEVER in CI** — Do NOT modify `FunctionalTestCase::setUpBeforeClass()` or `setupDatabaseSchema()`. Do NOT add CI-specific schema setup steps. Tests must work from a fresh `docker compose up` (from `fresh-environment-schema-setup-20260131.md` + `critical-patterns.md`).

### High (should follow)

4. **Doctrine ORM required for tests** — Even though this is a DBAL-only package, tests require full ORM configuration with `enable_native_lazy_objects: true` (PHP 8.4). Do NOT remove or modify the Doctrine ORM section in `test.yaml` (from `doctrine-transaction-middleware-orm-configuration.md`).

5. **Full namespace in stamp headers** — `MessageIdStamp` stays at `Freyr\MessageBroker\Stamp\MessageIdStamp`. `MessageNameStamp` moves to `Freyr\MessageBroker\Stamp\MessageNameStamp` (safe — package unreleased, no existing headers in production). Stamps are serialised with full FQN in `X-Message-Stamp-*` headers (from `phase-1-test-implementation-discoveries.md`).

6. **Middleware priority matters** — `DeduplicationMiddleware` runs at priority -10 (after `doctrine_transaction` at 0). The `OutboxPublishingMiddleware` should keep the same position in the middleware chain as the current `OutboxToAmqpBridge` — explicitly listed AFTER `doctrine_transaction` in the bus config (from `deduplication-middleware-not-running-in-tests.md`).

7. **Infrastructure failures must fail fast** — No `continue-on-error: true` in CI for setup steps. If adding new CI steps for this refactoring, include verification (from `hidden-schema-failures-fresh-environment.md`).

### Implementation checklist (from learnings)

- [ ] `OutboxPublishingMiddleware` listed in `messenger.yaml` bus middleware (not just tagged)
- [ ] `tests/Functional/config/test.yaml` updated in same commit as `config/services.yaml`
- [ ] `FunctionalTestCase::setUpBeforeClass()` NOT modified
- [ ] Doctrine ORM config with `enable_native_lazy_objects: true` preserved
- [ ] `MessageIdStamp` namespace unchanged (`Freyr\MessageBroker\Stamp\`)
- [ ] `MessageNameStamp` moved to `Freyr\MessageBroker\Stamp\` (alongside MessageIdStamp)
- [ ] `OutboxMessage` moved to `Freyr\MessageBroker\Outbox\` (out of EventBridge/)
- [ ] `ResolvesFromClass` moved to `Freyr\MessageBroker\Attribute\` (shared namespace)
- [ ] All imports updated for the three moved files
- [ ] Run `docker compose run --rm php vendor/bin/phpunit` from fresh environment after changes
- [ ] Verify with `docker compose run --rm php bin/console debug:messenger --env=test` that middleware is in bus stack

## References

### Internal

- Brainstorm: `docs/brainstorms/2026-02-12-transport-agnostic-architecture-brainstorm.md`
- Issue: #24
- Current bridge: `src/Outbox/EventBridge/OutboxToAmqpBridge.php`
- Current routing: `src/Outbox/Routing/DefaultAmqpRoutingStrategy.php`
- Critical patterns: `docs/solutions/patterns/critical-patterns.md`
- Recipe: `recipe/1.0/config/packages/messenger.yaml`

### Symfony Documentation

- [Messenger: Sync & Queued Message Handling](https://symfony.com/doc/current/messenger.html)
- [Service Subscribers & Locators](https://symfony.com/doc/current/service_container/service_subscribers_locators.html)
- [Service Tags & Compiler Passes](https://symfony.com/doc/current/service_container/tags.html)
- [Custom Messenger Transport](https://symfony.com/doc/current/messenger/custom-transport.html)
- [MessengerPass source](https://github.com/symfony/messenger/blob/7.2/DependencyInjection/MessengerPass.php)

### Framework Comparisons

- [Ecotone Framework — Message Channel abstraction](https://docs.ecotone.tech/)
- [Laravel Queue — Connection + Queue separation](https://laravel.com/docs/12.x/queues)
- [Symfony Notifier — Plugin transport pattern](https://symfony.com/doc/current/notifier.html)

### Routing Conventions

- [RabbitMQ naming conventions](https://eng.revinate.com/2015/12/01/rabbitmq-naming-conventions.html)
- [Kafka topic naming conventions (Confluent)](https://www.confluent.io/learn/kafka-topic-naming-convention/)
- [SNS/SQS naming conventions](https://www.edureka.co/community/14055/what-are-the-naming-conventions-for-sns-sqs)

### Institutional Learnings

- `docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md`
- `docs/solutions/test-failures/doctrine-transaction-middleware-orm-configuration.md`
- `docs/solutions/test-failures/phase-1-test-implementation-discoveries.md`
- `docs/solutions/test-failures/fresh-environment-schema-setup-20260131.md`
- `docs/solutions/ci-issues/hidden-schema-failures-fresh-environment.md`
- `docs/solutions/patterns/critical-patterns.md`
- `docs/solutions/database-issues/migration-schema-mismatch-ci-vs-local.md`
