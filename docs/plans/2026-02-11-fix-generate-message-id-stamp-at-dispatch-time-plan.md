---
title: "fix: Generate MessageIdStamp at dispatch time, not in bridge"
type: fix
date: 2026-02-11
deepened: 2026-02-11
---

# fix: Generate MessageIdStamp at dispatch time, not in bridge

## Enhancement Summary

**Deepened on:** 2026-02-11
**Agents used:** architecture-strategist, performance-oracle, security-sentinel, pattern-recognition-specialist, code-simplicity-reviewer, deployment-verification-agent, data-integrity-guardian, framework-docs-researcher, best-practices-researcher

### Key Improvements from Review

1. **Replace nested bus dispatch with direct `SenderInterface` injection** — eliminates nested savepoints, double middleware traversal, `DeferredMessageBus`, and guard #3 (unanimous recommendation across 4 agents)
2. **Make outbox transport name configurable** via constructor parameter (follows existing `$tableName` pattern in `DeduplicationDbalStore`)
3. **`messenger.middleware` tag priority has no effect** in standard Symfony — explicit listing in bus config is the only reliable ordering mechanism
4. **Industry validation** — every major outbox implementation (Debezium, MassTransit, NServiceBus, Wolverine) generates message IDs at dispatch time
5. **Document both serialisation paths** — outbox stamp persistence works via `PhpSerializer` (default) OR `OutboxSerializer` (X-Message-Id header), depending on transport config
6. **Add mandatory short-circuit comment** explaining `HandleMessageMiddleware` would throw `NoHandlerForMessageException` without it

### Considerations Noted (Not Blocking)

- **Security**: `OutboxSerializer::decode()` has unvalidated `X-Message-Class` header (pre-existing, not introduced by this change)
- **Test config divergence**: Outbox transport uses `OutboxSerializer` in tests but `PhpSerializer` in recipe (pre-existing)

---

## Overview

`OutboxToAmqpBridge` generates a **new** `MessageIdStamp` every time it processes a message from the outbox. This breaks deduplication guarantees when AMQP publish succeeds but outbox ACK fails — redelivery generates a different ID, causing duplicate processing downstream.

**Fix:** Generate `MessageIdStamp` at dispatch time via a new `MessageIdStampMiddleware`, and convert `OutboxToAmqpBridge` from handler to middleware so it can read the existing stamp from the envelope. The bridge publishes to AMQP via direct `SenderInterface` injection (not nested bus dispatch).

## Problem Statement

### Current (broken) flow

```
1. Application dispatches OrderPlaced → stored in messenger_outbox (NO MessageIdStamp)
2. Worker consumes from outbox → bridge generates NEW MessageIdStamp(A) → publishes to AMQP
3. AMQP publish succeeds, but worker crashes before outbox ACK
4. Worker restarts → consumes same message → bridge generates NEW MessageIdStamp(B)
5. Consumer's DeduplicationMiddleware sees ID B (different from A) → processes AGAIN
```

The root cause: `MessageIdStamp` is generated **inside** the bridge handler (`src/Outbox/EventBridge/OutboxToAmqpBridge.php:43`: `$messageId = Id::new()`), which runs on every consumption attempt rather than once at dispatch time.

### Industry validation

Every major outbox implementation generates message IDs at dispatch time:

| Implementation | ID Generation Point | Source |
|---|---|---|
| Debezium | `uuid` column in outbox INSERT (within business transaction) | [Debezium Docs](https://debezium.io/documentation/reference/stable/transformations/outbox-event-router.html) |
| MassTransit | `MessageId` assigned at publish/send time | [MassTransit Docs](https://masstransit.io/documentation/patterns/transactional-outbox) |
| NServiceBus | `MessageId` stored with business data in same transaction | [NServiceBus Docs](https://docs.particular.net/nservicebus/outbox/) |
| Wolverine | `Envelope.Id` persisted at storage time | [Wolverine Docs](https://wolverinefx.net/guide/durability/) |

Chris Richardson (Microservices.io): *"The Message relay might publish a message more than once. It might crash after publishing a message but before recording the fact that it has done so."* — The outbox pattern assumes stable IDs so consumers can deduplicate.

### Fixed flow

```
1. Application dispatches OrderPlaced → MessageIdStampMiddleware adds MessageIdStamp(X)
   → stored in messenger_outbox WITH stamp
2. Worker consumes → bridge reads existing MessageIdStamp(X) → publishes to AMQP
3. AMQP publish succeeds, but worker crashes before outbox ACK
4. Worker restarts → consumes same message → bridge reads SAME MessageIdStamp(X)
5. Consumer's DeduplicationMiddleware sees ID X (same) → skips duplicate
```

## Key Architectural Constraint

Symfony Messenger handlers only receive the **unwrapped message object** — they cannot access envelope stamps. The `HandleMessageMiddleware` calls the handler with just the message, not the `Envelope`. Since the bridge needs to **read** `MessageIdStamp` from the envelope, it must be converted from a handler to a **middleware** (which receives the full `Envelope`).

### Stamp survival through outbox serialisation

`MessageIdStamp` added at dispatch time survives the outbox round-trip via **two independent mechanisms** (depending on transport configuration):

1. **`PhpSerializer` (default when no `serializer:` key on transport):** Serialises the entire `Envelope` including stamps via PHP's native `serialize()`. All stamps that do not implement `NonSendableStampInterface` are preserved. `MessageIdStamp` does not implement `NonSendableStampInterface`, so it survives.

2. **`OutboxSerializer` (when explicitly configured on outbox transport):** Extracts `MessageIdStamp` to `X-Message-Id` header during `encode()` (`src/Serializer/OutboxSerializer.php:76-81`), restores it during `decode()` (`src/Serializer/OutboxSerializer.php:114-139`).

Both paths correctly round-trip the stamp. The recipe uses `PhpSerializer` (no custom serializer on outbox transport); the functional tests use `OutboxSerializer`.

## Proposed Solution

### New middleware: `MessageIdStampMiddleware`

A lightweight middleware that stamps `OutboxMessage` envelopes at dispatch time.

**Guards:**
1. Only stamp `OutboxMessage` instances (non-outbox messages pass through)
2. Skip if `ReceivedStamp` present (redelivery from transport — stamp already exists)
3. Only stamp if `MessageIdStamp` not already present (idempotent — supports explicit stamps from application code, e.g. event replays or deterministic testing)

### Refactored bridge: `OutboxToAmqpBridge` as middleware with direct sender

Convert from `#[AsMessageHandler(fromTransport: 'outbox')]` to `MiddlewareInterface`.

**Behaviour:**
- Check if message is `OutboxMessage` AND has `ReceivedStamp` with configurable transport name (default `'outbox'`)
- If not: pass through to `$stack->next()->handle()`
- If yes: read existing `MessageIdStamp` (throw `RuntimeException` if missing), publish to AMQP via **direct `SenderInterface`**, **short-circuit** (return without calling `$stack->next()`)

### Why direct `SenderInterface` instead of bus dispatch

The original plan used `$this->eventBus->dispatch()` for AMQP publishing. Multi-agent review identified this as the highest-impact simplification opportunity:

| Concern | Nested bus dispatch | Direct `SenderInterface` |
|---|---|---|
| Middleware re-execution | Full chain traversed again (~11 calls) | No re-execution |
| Nested savepoint | `doctrine_transaction` creates SAVEPOINT + RELEASE | No nested transaction |
| Guard #3 in `MessageIdStampMiddleware` | Needed to prevent stamp overwriting | Not needed |
| `DeferredMessageBus` test helper | Needed for circular dependency | Not needed |
| Complexity | Must reason about nested middleware interactions | Single direct call |
| Performance (1K outbox msgs/s) | +2,000 unnecessary SAVEPOINT SQL/s | Zero overhead |

**Service config:**
```yaml
Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge:
    arguments:
        $amqpSender: '@messenger.transport.amqp'
        $routingStrategy: '@Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface'
        $logger: '@logger'
```

## Middleware Execution Flows

### Flow 1: Dispatch (new OutboxMessage)

```
Application → $bus->dispatch(new OrderPlaced(...))
  │
  ├─ MessageIdStampMiddleware
  │   ├─ Is OutboxMessage? YES
  │   ├─ Has ReceivedStamp? NO
  │   ├─ Has MessageIdStamp? NO
  │   └─ ACTION: Add MessageIdStamp(UUID v7) → call next
  │
  ├─ doctrine_transaction → BEGIN TRANSACTION → call next
  │
  ├─ OutboxToAmqpBridge
  │   ├─ Is OutboxMessage? YES
  │   ├─ Has ReceivedStamp('outbox')? NO (no ReceivedStamp at all)
  │   └─ ACTION: Pass through → call next
  │
  ├─ DeduplicationMiddleware
  │   ├─ Has ReceivedStamp? NO
  │   └─ ACTION: Pass through → call next
  │
  ├─ SendMessageMiddleware
  │   └─ ACTION: Route to 'outbox' transport → stamp serialised → return
  │
  └─ doctrine_transaction → COMMIT
```

### Flow 2: Outbox consumption (bridge publishes to AMQP)

```
Worker → messenger:consume outbox
  │  DoctrineReceiver deserialises message (stamps restored)
  │  Adds ReceivedStamp('outbox')
  │
  ├─ MessageIdStampMiddleware
  │   ├─ Has ReceivedStamp? YES
  │   └─ ACTION: Skip → call next
  │
  ├─ doctrine_transaction → BEGIN TRANSACTION → call next
  │
  ├─ OutboxToAmqpBridge
  │   ├─ Is OutboxMessage? YES
  │   ├─ Has ReceivedStamp('outbox')? YES
  │   ├─ Read MessageIdStamp → found (stamp X)
  │   ├─ ACTION: Build AMQP envelope → $amqpSender->send() (direct, no bus)
  │   └─ SHORT-CIRCUIT: return envelope
  │       (mandatory: HandleMessageMiddleware has no handler for OutboxMessage)
  │
  └─ doctrine_transaction → COMMIT
      Worker ACKs outbox row (separate from transaction)
```

### Flow 3: Inbox consumption (AMQP → handler)

```
Worker → messenger:consume amqp_orders
  │  InboxSerializer translates semantic name → FQN
  │  Restores MessageIdStamp from X-Message-Id header
  │  Adds ReceivedStamp('amqp_orders')
  │
  ├─ MessageIdStampMiddleware
  │   ├─ Is OutboxMessage? NO (inbox message class)
  │   └─ ACTION: Pass through → call next
  │
  ├─ doctrine_transaction → BEGIN TRANSACTION → call next
  │
  ├─ OutboxToAmqpBridge
  │   ├─ Has ReceivedStamp('outbox')? NO (from 'amqp_orders')
  │   └─ ACTION: Pass through → call next
  │
  ├─ DeduplicationMiddleware
  │   ├─ Has ReceivedStamp? YES
  │   ├─ Has MessageIdStamp? YES (stamp X)
  │   └─ ACTION: Check dedup store → new: INSERT + continue / duplicate: short-circuit
  │
  ├─ HandleMessageMiddleware → invoke handler
  │
  └─ doctrine_transaction → COMMIT (dedup INSERT + handler work atomic)
      Worker ACKs AMQP message
```

## Technical Considerations

### Middleware ordering

**Corrected (2026-02-28):** The `messenger.middleware` tag DOES auto-register middleware into buses. The `priority` attribute on the tag controls execution order. Explicit listing in the bus `middleware` config is useful when you need precise ordering alongside built-in middleware like `doctrine_transaction`.

User-facing `messenger.yaml` configuration:
```yaml
middleware:
    - 'Freyr\MessageBroker\Outbox\MessageIdStampMiddleware'
    - doctrine_transaction
    - 'Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge'
    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'
```

### Short-circuit is mandatory

The bridge must return without calling `$stack->next()` because the `#[AsMessageHandler]` attribute is removed. `HandleMessageMiddleware` will find no handler for `OutboxMessage` and throw `NoHandlerForMessageException`. The short-circuit prevents this. This is the same pattern used by `SendMessageMiddleware` when routing messages to transports.

### Backward compatibility — legacy outbox messages

Messages stored in `messenger_outbox` before deployment will lack `MessageIdStamp`. The bridge throws `RuntimeException` with an actionable message.

**Deployment strategy:** Drain the outbox before deploying (`messenger:consume outbox` until empty). See Deployment Checklist below.

### Transaction semantics

The bridge's short-circuit means `doctrine_transaction` commits an empty transaction (no Doctrine entities changed). The outbox ACK (row deletion) happens **after** the middleware chain returns, outside the transaction scope. This is correct Symfony Messenger behaviour.

### Pass-through performance

Each non-outbox message pays a minimal tax of ~5 `instanceof` checks and ~3 stamp lookups across both new middleware. On PHP 8.4, this is approximately 0.1-0.5 microseconds per message — negligible. UUID v7 generation (~1 microsecond, amortised) only occurs for `OutboxMessage` dispatches.

## Acceptance Criteria

- [x] `MessageIdStamp` is present on the envelope **before** it reaches the outbox transport (at dispatch time)
- [x] Outbox-stored message headers contain `MessageIdStamp` (verified via serialised output)
- [x] Bridge reads the **same** `MessageIdStamp` from the outbox envelope (not generating a new one)
- [x] AMQP-published message carries the **same** `MessageIdStamp` as stored in outbox
- [x] Redelivery scenario: same outbox message processed twice by bridge → same `MessageIdStamp` on both AMQP publishes
- [x] Non-`OutboxMessage` types pass through `MessageIdStampMiddleware` without stamping
- [x] Non-outbox-transport messages pass through `OutboxToAmqpBridge` without processing
- [x] Bridge throws `RuntimeException` if `MessageIdStamp` is missing from outbox envelope
- [x] All existing unit and functional tests pass
- [x] `CLAUDE.md` documentation updated to reflect new architecture

## Implementation Plan

### Phase 1: Source code changes

#### 1.1 Create `src/Outbox/MessageIdStampMiddleware.php` (NEW)

```php
// src/Outbox/MessageIdStampMiddleware.php
final readonly class MessageIdStampMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        if ($envelope->last(ReceivedStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Only stamp if not already present (idempotent)
        if ($envelope->last(MessageIdStamp::class) === null) {
            $envelope = $envelope->with(new MessageIdStamp((string) Id::new()));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
```

No constructor dependencies. Two guard clauses + conditional stamp. Single responsibility.

#### 1.2 Refactor `src/Outbox/EventBridge/OutboxToAmqpBridge.php` (MODIFY)

Convert from handler to middleware with direct sender injection:
- Remove `#[AsMessageHandler(fromTransport: 'outbox')]` attribute
- Implement `MiddlewareInterface`
- Replace `MessageBusInterface $eventBus` with `SenderInterface $amqpSender`
- Add configurable `string $outboxTransportName = 'outbox'`

```php
// src/Outbox/EventBridge/OutboxToAmqpBridge.php
final readonly class OutboxToAmqpBridge implements MiddlewareInterface
{
    public function __construct(
        private SenderInterface $amqpSender,
        private AmqpRoutingStrategyInterface $routingStrategy,
        private LoggerInterface $logger,
        private string $outboxTransportName = 'outbox',
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        $receivedStamp = $envelope->last(ReceivedStamp::class);
        if (!$receivedStamp instanceof ReceivedStamp
            || $receivedStamp->getTransportName() !== $this->outboxTransportName) {
            return $stack->next()->handle($envelope, $stack);
        }

        $event = $envelope->getMessage();

        $messageName = MessageName::fromClass($event)
            ?? throw new RuntimeException(sprintf(
                'Event %s must have #[MessageName] attribute',
                $event::class,
            ));

        $messageIdStamp = $envelope->last(MessageIdStamp::class)
            ?? throw new RuntimeException(sprintf(
                'OutboxMessage %s consumed from outbox transport without MessageIdStamp. '
                . 'Ensure MessageIdStampMiddleware runs before outbox transport storage, '
                . 'or drain the outbox of legacy messages before deployment.',
                $event::class,
            ));

        $routingKey = $this->routingStrategy->getRoutingKey($event, $messageName);
        $headers = $this->routingStrategy->getHeaders($messageName);

        $amqpEnvelope = new Envelope($event, [
            $messageIdStamp,
            new AmqpStamp($routingKey, AMQP_NOPARAM, $headers),
        ]);

        $this->logger->info('Publishing event to AMQP', [
            'message_name' => $messageName,
            'message_id' => $messageIdStamp->messageId,
            'event_class' => $event::class,
            'routing_key' => $routingKey,
        ]);

        $this->amqpSender->send($amqpEnvelope);

        // Short-circuit: OutboxMessage is fully handled by this middleware.
        // HandleMessageMiddleware has no handler for OutboxMessage — calling
        // $stack->next() would throw NoHandlerForMessageException.
        return $envelope;
    }
}
```

**Changes from original plan:**
- `SenderInterface $amqpSender` replaces `MessageBusInterface $eventBus` (no nested dispatch)
- `$amqpSender->send()` replaces `$this->eventBus->dispatch()` (direct transport call)
- `$outboxTransportName` constructor parameter (configurable, default `'outbox'`)
- No `TransportNamesStamp` needed (direct sender bypasses routing)
- Actionable error message for missing `MessageIdStamp` includes deployment guidance

#### 1.3 Update `src/Stamp/MessageIdStamp.php` (MODIFY — docblock only)

Change "Created by OutboxToAmqpBridge" to "Created by MessageIdStampMiddleware at dispatch time".

### Phase 2: Configuration changes

#### 2.1 Update `config/services.yaml` (MODIFY)

```yaml
# NEW: MessageIdStamp Middleware
Freyr\MessageBroker\Outbox\MessageIdStampMiddleware:
    tags:
        - { name: 'messenger.middleware' }

# CHANGED: Bridge is now middleware with direct sender injection
Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge:
    arguments:
        $amqpSender: '@messenger.transport.amqp'
        $routingStrategy: '@Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface'
        $logger: '@logger'
    tags:
        - { name: 'messenger.middleware' }
```

#### 2.2 Update `tests/Functional/config/test.yaml` (MODIFY)

Middleware list:
```yaml
middleware:
    - 'Freyr\MessageBroker\Outbox\MessageIdStampMiddleware'
    - doctrine_transaction
    - 'Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge'
    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'
```

Service definitions:
```yaml
# NEW
Freyr\MessageBroker\Outbox\MessageIdStampMiddleware: ~

# CHANGED — direct sender, no autoconfigure
Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge:
    arguments:
        $amqpSender: '@messenger.transport.amqp'
        $routingStrategy: '@Freyr\MessageBroker\Outbox\Routing\AmqpRoutingStrategyInterface'
        $logger: '@logger'
```

### Phase 3: Test infrastructure changes

#### 3.1 Update `tests/Unit/Factory/EventBusFactory.php` (MODIFY)

**`createForOutboxTesting()`:** Add `MessageIdStampMiddleware` before `SendMessageMiddleware`:
```php
$middleware = [
    new MessageIdStampMiddleware(),
    new SendMessageMiddleware($senderLocator),
    new HandleMessageMiddleware($handlersLocator),
];
```

**`createForInboxFlowTesting()`:** Add both middleware with mock sender:
```php
// Bridge uses the AMQP publish transport directly (no circular dependency)
$bridgeMiddleware = new OutboxToAmqpBridge(
    amqpSender: $amqpPublishTransport,  // InMemoryTransport implements TransportInterface
    routingStrategy: new DefaultAmqpRoutingStrategy(),
    logger: new NullLogger(),
);

$middleware = [
    new MessageIdStampMiddleware(),
    $sendMiddleware,
    $bridgeMiddleware,
    $deduplicationMiddleware,
    new HandleMessageMiddleware($handlersLocator),
];

$bus = new MessageBus($middleware);
```

**No `DeferredMessageBus` needed** — `InMemoryTransport` implements `TransportInterface` which extends `SenderInterface`, so it can be passed directly.

### Phase 4: Test rewrites

#### 4.1 Create `tests/Unit/MessageIdStampMiddlewareTest.php` (NEW)

Test cases:
- `OutboxMessage` gets stamped with `MessageIdStamp` (valid UUID v7)
- Non-`OutboxMessage` passes through without stamp
- `OutboxMessage` with existing `MessageIdStamp` is not re-stamped (idempotent)
- `OutboxMessage` with `ReceivedStamp` is not stamped (redelivery)

#### 4.2 Rewrite `tests/Unit/OutboxToAmqpBridgeTest.php` (MODIFY)

Test cases:
- `OutboxMessage` with `ReceivedStamp('outbox')` + `MessageIdStamp` → sender receives envelope with **same** stamp
- Missing `MessageIdStamp` → throws `RuntimeException`
- Non-`OutboxMessage` → passes through to next middleware
- `OutboxMessage` without `ReceivedStamp` → passes through
- `OutboxMessage` with `ReceivedStamp('amqp')` (wrong transport) → passes through

**Testing is simpler with `SenderInterface`:** inject a mock/spy sender and assert `send()` is called with correct envelope stamps. No need for `DeferredMessageBus` or capturing bus-level state.

#### 4.3 Update `tests/Unit/InboxFlowTest.php` (MODIFY)

Replace manual `$bridge->__invoke($originalMessage)` calls with bus re-dispatch using `ReceivedStamp('outbox')`. The bridge middleware intercepts and calls `$amqpPublishTransport->send()` directly.

Pattern:
```php
// Old: manual bridge invocation
$bridge->__invoke($originalMessage);

// New: re-dispatch through bus with ReceivedStamp (triggers bridge middleware)
$outboxEnvelopes = $context->outboxTransport->get();
foreach ($outboxEnvelopes as $envelope) {
    $context->bus->dispatch($envelope->with(new ReceivedStamp('outbox')));
}
```

### Phase 5: Documentation

#### 5.1 Update `CLAUDE.md` (MODIFY)

- Update outbox flow diagram to show `MessageIdStampMiddleware` adding stamp at dispatch
- Update `OutboxToAmqpBridge` description (now middleware with `SenderInterface`, reads stamp instead of generating)
- Update services configuration examples (replace `$eventBus` with `$amqpSender`)
- Update middleware ordering documentation
- Remove `#[AsMessageHandler]` references from bridge documentation

## Files to Modify

| File | Action | Description |
|---|---|---|
| `src/Outbox/MessageIdStampMiddleware.php` | **CREATE** | New middleware — stamps OutboxMessage at dispatch |
| `src/Outbox/EventBridge/OutboxToAmqpBridge.php` | **MODIFY** | Handler → middleware with `SenderInterface` |
| `src/Stamp/MessageIdStamp.php` | **MODIFY** | Docblock update |
| `config/services.yaml` | **MODIFY** | Register new middleware, update bridge |
| `tests/Functional/config/test.yaml` | **MODIFY** | Middleware list + service definitions |
| `tests/Unit/Factory/EventBusFactory.php` | **MODIFY** | Add middleware to both factory methods |
| `tests/Unit/MessageIdStampMiddlewareTest.php` | **CREATE** | Unit tests for new middleware |
| `tests/Unit/OutboxToAmqpBridgeTest.php` | **MODIFY** | Rewrite for middleware API |
| `tests/Unit/InboxFlowTest.php` | **MODIFY** | Use middleware chain instead of manual invocation |
| `CLAUDE.md` | **MODIFY** | Documentation updates |

## Deployment Checklist

### Pre-Deploy (Required — all must pass)

1. **Drain the outbox completely:**
   ```sql
   SELECT COUNT(*) AS pending FROM messenger_outbox WHERE delivered_at IS NULL;
   -- MUST be 0
   ```
2. Stop all Messenger workers (outbox + inbox consumers)
3. Save baseline counts:
   ```sql
   SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed';
   SELECT COUNT(*) FROM message_broker_deduplication;
   ```

### Deploy

1. Deploy new commit
2. Clear Symfony container cache (`cache:clear --env=prod`)
3. Verify with `debug:messenger`:
   - `OutboxToAmqpBridge` appears as **middleware**, NOT as handler
   - `MessageIdStampMiddleware` appears in middleware list
4. Start outbox worker, then inbox workers
5. Re-enable message dispatching

### Post-Deploy (Within 5 Minutes)

1. Trigger a domain event that dispatches an `OutboxMessage`
2. Verify worker logs show `Publishing event to AMQP` with `message_id`
3. Verify no `RuntimeException` in worker logs
4. Check failed message count has not increased
5. Check outbox queue is not accumulating

### Rollback

- **Safe to rollback** — no database migration, no schema changes
- Stop workers → deploy previous commit → clear cache → restart workers
- The old bridge generates new `MessageIdStamp` (current behaviour) — not ideal but functional
- Retry any failed messages: `messenger:failed:retry --all`

## Dependencies & Risks

**Risks:**
- **Legacy outbox messages:** Bridge throws `RuntimeException` if `MessageIdStamp` missing — drain outbox before deployment
- **Middleware ordering:** Explicit listing in YAML prevents silent misconfiguration (institutional learning)

**Mitigated (by direct sender approach):**
- ~~Nested dispatch~~ — eliminated by `SenderInterface`
- ~~Nested savepoints~~ — eliminated
- ~~DeferredMessageBus test fragility~~ — eliminated
- ~~Guard #3 complexity~~ — simplified to conditional stamp

**No external dependencies added.** All changes use existing Symfony Messenger interfaces (`MiddlewareInterface`, `SenderInterface`).

## References

### Internal
- `src/Inbox/DeduplicationMiddleware.php` — middleware pattern template
- `src/Serializer/OutboxSerializer.php:76-81` — `X-Message-Id` header handling
- `src/Inbox/DeduplicationDbalStore.php:23` — `$tableName` default parameter pattern (used for `$outboxTransportName`)
- `docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md` — middleware must be explicitly listed

### Symfony
- `MiddlewareInterface` — `Symfony\Component\Messenger\Middleware\MiddlewareInterface`
- `SenderInterface` — `Symfony\Component\Messenger\Transport\Sender\SenderInterface`
- `ReceivedStamp::getTransportName()` — transport identification for middleware
- `FrameworkExtension` — middleware chain assembly (before/custom/after merge)

### Industry
- [Microservices.io — Transactional Outbox](https://microservices.io/patterns/data/transactional-outbox.html)
- [Kamil Grzybek — The Outbox Pattern](https://www.kamilgrzybek.com/blog/posts/the-outbox-pattern)
- [NServiceBus — Outbox](https://docs.particular.net/nservicebus/outbox/)
- [MassTransit — Transactional Outbox](https://masstransit.io/documentation/patterns/transactional-outbox)
- [Event-Driven.io — Outbox, Inbox patterns explained](https://event-driven.io/en/outbox_inbox_patterns_and_delivery_guarantees_explained/)
