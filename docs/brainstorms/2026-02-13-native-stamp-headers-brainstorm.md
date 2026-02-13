# Brainstorm: Native Stamp Headers — Remove Stamp Header Manipulation

**Date:** 2026-02-13
**Status:** Decision captured
**Follows:** `2026-02-13-26-stamp-first-serializer-simplification-brainstorm.md`
**Next:** `/workflows:plan`

## What We're Building

Remove all `X-Message-Stamp-*` stripping and re-injection from both serialisers. Let Symfony's native stamp serialisation handle `MessageIdStamp` and `MessageNameStamp` transport. Serialisers only translate the `type` header (semantic name vs FQN).

## Why This Approach

**Problem:** The current serialisers do significant work to hide PHP FQNs from the wire format:

- `WireFormatSerializer.encode()` strips `X-Message-Stamp-MessageIdStamp` and `X-Message-Stamp-MessageNameStamp`, replaces with semantic `X-Message-Id` header
- `WireFormatSerializer.decode()` re-injects `X-Message-Stamp-*` headers from semantic headers, then delegates to parent
- `InboxSerializer.decode()` strips `X-Message-Stamp-MessageIdStamp`, manually creates stamp from `X-Message-Id`
- `InboxSerializer.encode()` strips `X-Message-Stamp-MessageIdStamp`, adds `X-Message-Id`

This is ~40 lines of stamp header manipulation per serialiser that exists solely to prevent BC breaks if stamp FQN changes and to keep PHP class names off the wire.

**Root cause:** Over-engineering. The stamp classes live in the shared `freyr/message-broker` package — both publisher and consumer use the same FQNs. BC breaks from namespace changes can be handled via a migration period (dual stamps) rather than a permanent abstraction layer.

**Key insight from user:** "When external consumers need a clean `X-Message-Id` header, I can add it **additively** — no stripping required."

## Key Decisions

1. **Stamps flow natively via `X-Message-Stamp-*` headers** — Symfony handles serialisation/deserialisation. No custom code needed.

2. **`type` header translation remains** — this is the core responsibility:
   - `WireFormatSerializer.encode()`: FQN → semantic name
   - `WireFormatSerializer.decode()`: semantic name → FQN (via `X-Message-Class`)
   - `InboxSerializer.decode()`: semantic name → consumer FQN (via `messageTypes` mapping)
   - `InboxSerializer.encode()`: consumer FQN → semantic name (via `MessageNameStamp`)

3. **`X-Message-Class` header stays** — `WireFormatSerializer` has no reverse mapping. The header is the only way to restore the publisher's FQN on the retry/failed decode path.

4. **`X-Message-Id` semantic header dropped** — message ID travels solely via native `X-Message-Stamp-*` headers. When external consumers need it, add it additively (YAGNI until then).

5. **No `X-Message-Id` fallback in InboxSerializer** — YAGNI. Non-PHP publishers are not a concern today.

6. **BC strategy for stamp namespace changes** — if `MessageIdStamp` or `MessageNameStamp` ever move namespace, introduce a dual-stamp migration period rather than a permanent wire-format abstraction.

## What Changes

| Component | Before | After |
|-----------|--------|-------|
| `WireFormatSerializer.encode()` | Strips stamp headers, adds `X-Message-Id` | Only: semantic `type`, `X-Message-Class` |
| `WireFormatSerializer.decode()` | Re-injects stamp headers from semantic headers | Only: restore FQN in `type` from `X-Message-Class` |
| `InboxSerializer.decode()` | Strips stamp header, manually creates stamps | Only: translate semantic `type` → FQN |
| `InboxSerializer.encode()` | Strips stamp header, adds `X-Message-Id` | Only: restore semantic `type` from stamp |
| `MESSAGE_ID_HEADER` constant | Used in both serialisers | Removed from both |

## Wire Format Comparison

**Before (semantic headers):**
```
type: order.placed
X-Message-Id: 01234567-89ab-7def-8000-000000000001
X-Message-Class: App\Event\OrderPlaced
Content-Type: application/json
```

**After (native stamps):**
```
type: order.placed
X-Message-Class: App\Event\OrderPlaced
X-Message-Stamp-Freyr\MessageBroker\Stamp\MessageIdStamp: [{"messageId":"01234567-89ab-7def-8000-000000000001"}]
X-Message-Stamp-Freyr\MessageBroker\Stamp\MessageNameStamp: [{"messageName":"order.placed"}]
Content-Type: application/json
```

## What This Eliminates

- `MESSAGE_ID_HEADER` constant from both serialisers
- All `X-Message-Stamp-*` stripping logic (4 `unset()` calls)
- All stamp re-injection / manual creation logic (~30 lines across both serialisers)
- Duplicate-avoidance `instanceof` guards for manual stamp attachment

## Open Questions

None — all decisions captured.

## Constraints

- `X-Message-Class` must remain on `WireFormatSerializer` (no reverse mapping available)
- `MessageNameStamp` is still needed for `InboxSerializer.encode()` to restore semantic name on retry
- Tests must be updated to expect native stamp headers instead of `X-Message-Id`
