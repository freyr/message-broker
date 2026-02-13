# Brainstorm: Stamp-First Serializer Simplification

**Date:** 2026-02-13
**Status:** Decision captured
**Next:** `/workflows:plan`

## What We're Building

Simplify the serializer architecture by making stamps the single source of truth for message metadata. Today, the semantic message name is resolved via reflection in three separate places. Instead, middleware creates stamps at dispatch time, the outbox doctrine transport uses native serialization, and a dedicated `WireFormatSerializer` handles only the external wire format (FQN to semantic name, stamp to `X-Message-Id` header).

## Why This Approach

**Problem identified during PR #25 review:**

1. `OutboxPublishingMiddleware` reflects `#[MessageName]` attribute, creates `MessageNameStamp`
2. `OutboxSerializer::encode()` reflects `#[MessageName]` again, **ignores the stamp**
3. `AmqpOutboxPublisher` reads the stamp (the only component doing it correctly)

The `OutboxSerializer` is also used on the outbox doctrine transport where it translates FQN to semantic names for storage — but this is unnecessary. The outbox is an internal implementation detail; only external transports need semantic names.

**Root cause:** Stamps and serializers were designed independently, leading to redundant reflection and unclear ownership of the semantic name.

## Key Decisions

1. **Separate middleware for MessageNameStamp** — new `MessageNameStampMiddleware` alongside existing `MessageIdStampMiddleware`. Each has single responsibility.

2. **Outbox doctrine transport uses fully native serializer** — no custom serializer. FQN in `type` header, stamps in `X-Message-Stamp-*` headers. Simplest option.

3. **New `WireFormatSerializer`** replaces `OutboxSerializer` on the AMQP/SQS transport — its only job is header translation for external wire format (FQN to semantic name, `MessageIdStamp` to `X-Message-Id`).

4. **Defensive `decode()` on WireFormatSerializer** — restores FQN from `X-Message-Class` header. Handles edge cases like `messenger:failed:retry` routing back through the AMQP transport.

5. **InboxSerializer left as-is** — already reads stamps for encode(), uses config mapping for decode(). No changes needed.

## Proposed Architecture

```
Dispatch:
  MessageIdStampMiddleware   → adds MessageIdStamp (existing, unchanged)
  MessageNameStampMiddleware → adds MessageNameStamp (NEW)
  → SendMessageMiddleware → Outbox doctrine transport (NATIVE serializer)

Outbox consumption:
  OutboxPublishingMiddleware → reads stamps from envelope (no reflection)
  → AmqpOutboxPublisher → reads stamps, adds AMQP routing config
    → sender.send() → WireFormatSerializer::encode()
      → reads MessageNameStamp → replaces type=FQN with semantic name
      → reads MessageIdStamp → adds X-Message-Id header

Failure:
  → Failed doctrine transport (NATIVE serializer — FQN preserved)

AMQP consumption (unchanged):
  → InboxSerializer::decode() → translates semantic name → FQN via config mapping
```

## What Changes

| Component | Before | After |
|-----------|--------|-------|
| `MessageIdStampMiddleware` | Unchanged | Unchanged |
| `MessageNameStampMiddleware` | Does not exist | NEW — adds `MessageNameStamp` at dispatch |
| Outbox doctrine transport | `OutboxSerializer` | Native serializer (no custom serializer) |
| `OutboxSerializer` | On both outbox + AMQP transports | **Deleted** — replaced by `WireFormatSerializer` |
| `WireFormatSerializer` | Does not exist | NEW — only on external transport (AMQP/SQS) |
| `OutboxPublishingMiddleware` | Reflects `#[MessageName]` | Reads `MessageNameStamp` from envelope |
| `AmqpOutboxPublisher` | Reads stamp (correct) | Unchanged |
| `InboxSerializer` | Unchanged | Unchanged |

## What This Eliminates

- `MessageName::fromClass()` call in `OutboxPublishingMiddleware`
- `MessageName::fromClass()` call in `OutboxSerializer::encode()`
- Custom serializer on outbox doctrine transport entirely
- The `OutboxSerializer` class

## Open Questions

None — all decisions captured.

## Constraints

- `WireFormatSerializer::encode()` must read stamps, not reflect attributes
- `WireFormatSerializer::decode()` must handle retry path defensively
- Native serializer on outbox transport means FQN visible in database — acceptable since it's internal
- `MessageNameStampMiddleware` must be idempotent (skip if stamp already present)
- `MessageNameStampMiddleware` must skip received messages (same pattern as `MessageIdStampMiddleware`)
