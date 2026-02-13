---
title: "refactor: Stamp-first serializer simplification"
type: refactor
date: 2026-02-13
issue: "#26"
brainstorm: docs/brainstorms/2026-02-13-26-stamp-first-serializer-simplification-brainstorm.md
---

# refactor: Stamp-first serializer simplification

## Overview

Make stamps the single source of truth for message metadata by eliminating redundant `#[MessageName]` reflection. A new `MessageNameStampMiddleware` stamps messages at dispatch time, the outbox doctrine transport switches to Symfony's native serialiser, and a new `WireFormatSerializer` replaces `OutboxSerializer` on the AMQP publishing transport.

**Root cause:** `MessageName::fromClass()` is called in three separate places (`OutboxPublishingMiddleware`, `OutboxSerializer::encode()`, and the new `MessageNameStampMiddleware` centralises it to one). The `OutboxSerializer` is used on the outbox doctrine transport where semantic names are unnecessary — the outbox is an internal implementation detail.

Part of #26

## Problem Statement

During PR #25 review, redundant reflection was identified:

1. `OutboxPublishingMiddleware` reflects `#[MessageName]`, creates `MessageNameStamp`
2. `OutboxSerializer::encode()` reflects `#[MessageName]` again, **ignores the stamp**
3. `AmqpOutboxPublisher` reads the stamp (the only component doing it correctly)

The `OutboxSerializer` also sits on the outbox doctrine transport, translating FQN to semantic names for internal storage — unnecessary complexity.

## Proposed Solution

### Architecture After Refactoring

```
Dispatch:
  MessageIdStampMiddleware   → adds MessageIdStamp (existing, unchanged)
  MessageNameStampMiddleware → adds MessageNameStamp (NEW — single reflection point)
  → SendMessageMiddleware → Outbox doctrine transport (NATIVE serialiser, FQN in type)

Outbox consumption:
  Native serialiser restores envelope with all stamps from X-Message-Stamp-* headers
  OutboxPublishingMiddleware → reads MessageNameStamp from envelope (no reflection)
  → AmqpOutboxPublisher → reads stamps, resolves routing
    → sender.send() → WireFormatSerializer::encode()
      → reads MessageNameStamp → replaces type=FQN with semantic name
      → reads MessageIdStamp → adds X-Message-Id header
      → adds X-Message-Class header (FQN, for retry path)

Inbox consumption (unchanged):
  → InboxSerializer::decode() → translates semantic name → FQN via config mapping
```

### What Changes

| Component | Before | After |
|-----------|--------|-------|
| `MessageNameStampMiddleware` | Does not exist | **NEW** — adds `MessageNameStamp` at dispatch |
| Outbox doctrine transport | `OutboxSerializer` | Native serialiser (no custom serialiser) |
| `OutboxSerializer` | On both outbox + AMQP transports | **Deleted** |
| `WireFormatSerializer` | Does not exist | **NEW** — only on AMQP publishing transport |
| `OutboxPublishingMiddleware` | Reflects `#[MessageName]` | Reads `MessageNameStamp` from envelope |
| `MessageIdStampMiddleware` | Unchanged | Unchanged |
| `AmqpOutboxPublisher` | Reads stamps (correct) | Unchanged |
| `InboxSerializer` | Unchanged | Unchanged |
| `DeduplicationMiddleware` | Unchanged | Unchanged |

## Technical Considerations

### Critical: In-Flight Message Migration

When the outbox doctrine transport switches from `OutboxSerializer` (semantic name in `type` header) to native serialiser (FQN in `type` header), **existing messages in `messenger_outbox` become unprocessable**. The native deserialiser tries to instantiate the semantic name as a class, which fails.

**Strategy: Drain before deploy.** This is a pre-1.0 package. The outbox table is transient (messages are consumed within seconds). Deployment procedure:

1. Stop outbox workers (`messenger:consume outbox`)
2. Wait for outbox to drain (or manually consume remaining messages)
3. Deploy new code
4. Start outbox workers

Document this in a UPGRADE note within the PR description.

### Middleware Ordering

```yaml
middleware:
    - 'Freyr\MessageBroker\Outbox\MessageIdStampMiddleware'
    - 'Freyr\MessageBroker\Outbox\MessageNameStampMiddleware'  # NEW — after MessageId
    - doctrine_transaction
    - 'Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware'
    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'
```

Both stamp middlewares run **before** `doctrine_transaction` and `SendMessageMiddleware` (in `default_middleware`), ensuring stamps are present when the envelope is serialised to the outbox.

### Fail-Fast Behaviour

`MessageNameStampMiddleware` **throws `RuntimeException` at dispatch time** if `#[MessageName]` is missing from an `OutboxMessage`. This prevents malformed messages from entering the outbox table. Matches the current behaviour where the error surfaces (just earlier in the pipeline).

### WireFormatSerializer Header Strategy

`WireFormatSerializer::encode()`:
- Calls `parent::encode()` to get base headers and body (FQN in `type`, stamps in `X-Message-Stamp-*`)
- Reads `MessageNameStamp` → overwrites `type` header with semantic name
- Reads `MessageIdStamp` → adds `X-Message-Id` header
- Adds `X-Message-Class` header (preserves FQN for retry path)
- Strips `X-Message-Stamp-MessageIdStamp` header (replaced by `X-Message-Id`)
- Strips `X-Message-Stamp-MessageNameStamp` header (replaced by `type`)

`WireFormatSerializer::decode()`:
- Reads `X-Message-Class` header → restores FQN in `type` header
- Reads `X-Message-Id` header → restores `MessageIdStamp`
- Reads semantic name from original `type` → restores `MessageNameStamp`
- Calls `parent::decode()` with FQN in `type`

This matches current `OutboxSerializer` behaviour for wire format backwards compatibility.

### Wire Format (Unchanged for External Consumers)

```
Headers:
  type: order.placed                    (semantic name — unchanged)
  X-Message-Id: 01234567-89ab-...      (UUID v7 — unchanged)
  X-Message-Class: App\Event\OrderPlaced  (FQN — unchanged)
  Content-Type: application/json

Body:
  {"orderId": "...", "totalAmount": 123.45, "placedAt": "2025-10-08T13:30:00+00:00"}
```

No `X-Message-Stamp-*` headers on the wire (stripped by `WireFormatSerializer`). External consumers see identical format to before.

### No Fallback in OutboxPublishingMiddleware

`OutboxPublishingMiddleware` reads `MessageNameStamp` from the envelope. If the stamp is missing, it throws `RuntimeException`. No fallback to attribute reflection — stamps are the single source of truth. This is safe because:
- At dispatch: `MessageNameStampMiddleware` guarantees the stamp is present
- At consumption: native serialiser restores stamps from `X-Message-Stamp-*` headers
- Deployment: outbox must be drained before deploy (see migration strategy)

### WireFormatSerializer::encode() Without Stamp

`WireFormatSerializer::encode()` **throws** if `MessageNameStamp` is missing. This serialiser is only used on the AMQP publishing transport, where messages always originate from the outbox flow (stamps guaranteed). Messages routed directly to AMQP (bypassing outbox) should use a different serialiser or the native serialiser.

## Acceptance Criteria

### Functional Requirements

- [x] `MessageNameStampMiddleware` adds `MessageNameStamp` at dispatch time for `OutboxMessage` envelopes
- [x] `MessageNameStampMiddleware` is idempotent (skips if stamp already present)
- [x] `MessageNameStampMiddleware` skips received messages (has `ReceivedStamp`)
- [x] `MessageNameStampMiddleware` skips non-`OutboxMessage` envelopes
- [x] `MessageNameStampMiddleware` throws `RuntimeException` if `#[MessageName]` missing on `OutboxMessage`
- [x] Outbox doctrine transport uses native serialiser (FQN in `type` header in database)
- [x] `WireFormatSerializer::encode()` translates FQN → semantic name in `type` header
- [x] `WireFormatSerializer::encode()` adds `X-Message-Id` and `X-Message-Class` headers
- [x] `WireFormatSerializer::encode()` strips `X-Message-Stamp-MessageIdStamp` and `X-Message-Stamp-MessageNameStamp`
- [x] `WireFormatSerializer::encode()` throws if `MessageNameStamp` missing
- [x] `WireFormatSerializer::decode()` restores FQN from `X-Message-Class` header
- [x] `WireFormatSerializer::decode()` restores stamps from semantic headers
- [x] `OutboxPublishingMiddleware` reads `MessageNameStamp` from envelope (no reflection)
- [x] `OutboxPublishingMiddleware` throws if `MessageNameStamp` missing
- [x] `OutboxSerializer` class is deleted
- [x] Wire format on AMQP is backwards-compatible (semantic `type`, `X-Message-Id`, `X-Message-Class`)
- [x] `MessageName::fromClass()` is only called in `MessageNameStampMiddleware`

### Non-Functional Requirements

- [x] All existing functional tests pass (updated as needed)
- [x] All existing unit tests pass (updated as needed)
- [x] No reflection calls in serialisers or `OutboxPublishingMiddleware`

## Implementation Phases

### Phase 1: New Middleware + Tests

**Files to create:**
- `src/Outbox/MessageNameStampMiddleware.php`
- `tests/Unit/Outbox/MessageNameStampMiddlewareTest.php`

**Implementation:**
- Follow `MessageIdStampMiddleware` pattern exactly (same guards: skip non-OutboxMessage, skip ReceivedStamp, skip if stamp exists)
- Use `MessageName::fromClass($message)` to extract semantic name
- Throw `RuntimeException` if attribute missing on `OutboxMessage`
- Create `MessageNameStamp` with the semantic name

**Tests (mirror `MessageIdStampMiddlewareTest`):**
- `OutboxMessage` receives `MessageNameStamp` at dispatch
- Non-`OutboxMessage` passes through without stamp
- Existing stamp is not overwritten (idempotent)
- Redelivered messages (with `ReceivedStamp`) don't get new stamps
- `OutboxMessage` without `#[MessageName]` throws `RuntimeException`

### Phase 2: WireFormatSerializer + Tests

**Files to create:**
- `src/Serializer/WireFormatSerializer.php`
- `tests/Unit/Serializer/WireFormatSerializerTest.php`

**Implementation:**
- Extends `Symfony\Component\Messenger\Transport\Serialization\Serializer` (same parent as current serialisers)
- Constructor: `$serializer` (Symfony serialiser service)
- `encode()`: call parent, then translate headers (FQN→semantic, stamp→X-Message-Id, add X-Message-Class, strip stamp headers)
- `decode()`: read X-Message-Class, restore FQN in type, restore stamps, call parent

**Tests:**
- `encode()` with valid envelope → semantic type, X-Message-Id, X-Message-Class headers
- `encode()` strips X-Message-Stamp-* headers for MessageIdStamp and MessageNameStamp
- `encode()` without `MessageNameStamp` → throws `RuntimeException`
- `encode()` without `MessageIdStamp` → throws `RuntimeException`
- `decode()` with X-Message-Class → restores FQN, stamps
- `decode()` round-trip: encode then decode produces equivalent envelope

### Phase 3: Modify OutboxPublishingMiddleware + Update Tests

**Files to modify:**
- `src/Outbox/OutboxPublishingMiddleware.php`
- `tests/Unit/Outbox/OutboxPublishingMiddlewareTest.php`

**Changes in `OutboxPublishingMiddleware`:**
- Remove `MessageName::fromClass($event)` call
- Read `MessageNameStamp` from `$envelope->last(MessageNameStamp::class)`
- Throw `RuntimeException` if stamp missing (new error message: `'Envelope for %s must contain MessageNameStamp.'`)
- Remove `use Freyr\MessageBroker\Outbox\MessageName` import

**Test updates:**
- `testThrowsWhenMessageNameAttributeMissing` → rename to `testThrowsWhenMessageNameStampMissing`, update fixture to omit stamp instead of attribute
- Verify envelope passed to publisher contains `MessageNameStamp` (already tested, but confirm stamp comes from envelope not reflection)

### Phase 4: Delete OutboxSerializer + Update Configuration

**Files to delete:**
- `src/Serializer/OutboxSerializer.php`

**Files to modify:**
- `config/services.yaml` — remove `OutboxSerializer` service, add `WireFormatSerializer` and `MessageNameStampMiddleware` services
- `tests/Functional/test.yaml` — outbox transport: remove `serializer:` key; AMQP transport: change to `WireFormatSerializer`

**Configuration changes in `config/services.yaml`:**

```yaml
# NEW: MessageNameStamp Middleware
Freyr\MessageBroker\Outbox\MessageNameStampMiddleware:
    tags:
        - { name: 'messenger.middleware' }

# NEW: Wire Format Serializer (replaces OutboxSerializer on AMQP transport)
Freyr\MessageBroker\Serializer\WireFormatSerializer:
    arguments:
        $serializer: '@serializer'

# REMOVE: OutboxSerializer service definition
```

**Transport configuration changes (user-facing, document in PR):**

```yaml
# BEFORE:
outbox:
    dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
    serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
amqp:
    serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'

# AFTER:
outbox:
    dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
    # No serializer key — uses native Symfony serializer
amqp:
    serializer: 'Freyr\MessageBroker\Serializer\WireFormatSerializer'
```

### Phase 5: Update Test Infrastructure + Functional Tests

**Files to modify:**
- `tests/Unit/Factory/EventBusFactory.php` — add `MessageNameStampMiddleware` to middleware chains, replace `OutboxSerializer` with native serialiser for outbox transport, replace with `WireFormatSerializer` for AMQP transport
- `tests/Unit/TransportSerializerTest.php` — update assertions: outbox transport stores FQN (not semantic name), AMQP transport uses `WireFormatSerializer`
- `tests/Functional/OutboxFlowTest.php` — update `testEventIsStoredInOutboxDatabase()` assertion: expect FQN in `type` header instead of semantic name
- `tests/Functional/FunctionalTestCase.php` — update `assertMessageInFailedTransport()` if it checks for `X-Message-Class` on outbox-originated failures

**Test infrastructure changes in `EventBusFactory`:**
- `createForOutboxTesting()`: add `new MessageNameStampMiddleware()` after `MessageIdStampMiddleware`
- `createSerializers()`: return native `Serializer` for outbox, `WireFormatSerializer` for AMQP
- `createForInboxFlowTesting()`: add `new MessageNameStampMiddleware()` to middleware chain

### Phase 6: Update Documentation + CLAUDE.md

**Files to modify:**
- `CLAUDE.md` — update architecture diagrams, middleware chain, serialiser references
- `README.md` — update configuration examples (OutboxSerializer → WireFormatSerializer, outbox transport native)
- `docs/amqp-routing.md` — verify no OutboxSerializer references

**PR description must include:**
- Migration note: drain outbox before deploying
- Configuration change: `OutboxSerializer` → `WireFormatSerializer` on AMQP transport
- Configuration change: remove `serializer:` from outbox doctrine transport

## Dependencies & Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| In-flight outbox messages during deploy | Messages become unprocessable | Drain outbox before deploy (documented) |
| Users referencing `OutboxSerializer` in config | Config breaks on upgrade | Document migration in PR, provide exact YAML diff |
| `EventBusFactory` test infrastructure changes | Many test failures during refactoring | Update factory first, then run tests incrementally |
| Wire format regression | External consumers break | Explicit assertion in `WireFormatSerializer` tests that output matches current format |

## References

- Brainstorm: `docs/brainstorms/2026-02-13-26-stamp-first-serializer-simplification-brainstorm.md`
- PR #25: Transport-agnostic core extraction (identified the redundancy)
- `src/Outbox/MessageIdStampMiddleware.php` — pattern to follow for new middleware
- `src/Serializer/OutboxSerializer.php` — class to be replaced
- `src/Outbox/OutboxPublishingMiddleware.php:62` — reflection to be removed
- `tests/Unit/Factory/EventBusFactory.php` — test infrastructure to update
- `docs/solutions/test-failures/phase-1-test-implementation-discoveries.md` — stamp namespace requirement
- `docs/solutions/patterns/critical-patterns.md` — test schema setup pattern
