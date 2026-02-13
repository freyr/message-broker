---
title: "refactor: Remove stamp header manipulation — native X-Message-Stamp-* headers"
type: refactor
date: 2026-02-13
brainstorm: docs/brainstorms/2026-02-13-native-stamp-headers-brainstorm.md
---

# refactor: Remove stamp header manipulation — native X-Message-Stamp-* headers

## Overview

Remove all `X-Message-Stamp-*` stripping and re-injection from `WireFormatSerializer` and `InboxSerializer`. Stamps flow natively via Symfony's `X-Message-Stamp-*` headers. Serialisers only translate the `type` header (semantic name vs FQN).

## Problem Statement

Both serialisers contain ~40 lines each of stamp header manipulation that exists solely to hide PHP FQNs from the wire format. This adds complexity, creates maintenance burden, and obscures the core responsibility: type header translation.

The `X-Message-Id` semantic header was designed to prevent BC breaks if stamp FQNs change. This is over-engineering — both publisher and consumer share the same `freyr/message-broker` package. Namespace changes can be handled via a dual-stamp migration period instead.

## Proposed Solution

Let Symfony's parent `Serializer` handle all stamp serialisation/deserialisation natively. Each custom serialiser shrinks to its core responsibility.

## Technical Approach

### WireFormatSerializer (after)

#### `encode()`

```php
public function encode(Envelope $envelope): array
{
    $messageNameStamp = $envelope->last(MessageNameStamp::class);
    if (!$messageNameStamp instanceof MessageNameStamp) {
        throw new RuntimeException(sprintf(
            'Envelope for %s must contain MessageNameStamp. Ensure MessageNameStampMiddleware runs at dispatch time.',
            $envelope->getMessage()::class,
        ));
    }

    $encoded = parent::encode($envelope);
    $headers = $encoded['headers'] ?? [];

    // Preserve FQN for retry/failed decode path
    $headers[self::MESSAGE_CLASS_HEADER] = $envelope->getMessage()::class;

    // Replace FQN with semantic name
    $headers['type'] = $messageNameStamp->messageName;

    $encoded['headers'] = $headers;

    return $encoded;
}
```

**Changes from current:**
- Remove `MessageIdStamp` validation (already validated by `OutboxPublishingMiddleware`)
- Remove `X-Message-Id` header addition
- Remove `X-Message-Stamp-*` stripping (stamps flow natively)

#### `decode()`

```php
public function decode(array $encodedEnvelope): Envelope
{
    $headers = $encodedEnvelope['headers'] ?? [];

    $semanticName = $headers['type'] ?? null;
    $fqn = $headers[self::MESSAGE_CLASS_HEADER] ?? null;

    // Restore FQN in type header (so parent can deserialise the message class)
    if (is_string($semanticName) && is_string($fqn) && !str_contains($semanticName, '\\')) {
        $headers['type'] = $fqn;
    }

    // Clean up wire-format-specific header
    unset($headers[self::MESSAGE_CLASS_HEADER]);

    $encodedEnvelope['headers'] = $headers;

    return parent::decode($encodedEnvelope);
}
```

**Changes from current:**
- Remove all stamp header re-injection logic
- Remove `X-Message-Id` extraction
- Only restore FQN in `type` from `X-Message-Class`, then clean up

#### Removals

- `MESSAGE_ID_HEADER` constant — deleted
- `use Freyr\MessageBroker\Stamp\MessageIdStamp` — deleted (no longer referenced)

### InboxSerializer (after)

#### `decode()`

```php
public function decode(array $encodedEnvelope): Envelope
{
    $headers = $encodedEnvelope['headers'] ?? [];

    // ... existing type validation ...

    $semanticName = $headers['type'] ?? null;
    $fqn = $this->messageTypes[$semanticName] ?? null;

    // ... existing FQN lookup + validation ...

    $headers['type'] = $fqn;
    $encodedEnvelope['headers'] = $headers;

    $envelope = parent::decode($encodedEnvelope);

    // Attach MessageNameStamp (needed for encode() on retry path)
    if (!$envelope->last(MessageNameStamp::class) instanceof MessageNameStamp) {
        $envelope = $envelope->with(new MessageNameStamp($semanticName));
    }

    return $envelope;
}
```

**Changes from current:**
- Remove `X-Message-Id` extraction (lines 83–85)
- Remove `X-Message-Stamp-MessageIdStamp` stripping (line 88)
- Remove manual `MessageIdStamp` creation (lines 102–105)
- **Keep** `MessageNameStamp` creation — needed for `encode()` on the retry path

#### `encode()`

```php
public function encode(Envelope $envelope): array
{
    $encoded = parent::encode($envelope);
    $headers = $encoded['headers'] ?? [];

    $messageNameStamp = $envelope->last(MessageNameStamp::class);
    if ($messageNameStamp instanceof MessageNameStamp) {
        $headers['type'] = $messageNameStamp->messageName;
    }

    $encoded['headers'] = $headers;

    return $encoded;
}
```

**Changes from current:**
- Remove `MessageIdStamp` → `X-Message-Id` header replacement (lines 135–139)
- Stamps flow natively — no stripping

#### Removals

- `MESSAGE_ID_HEADER` constant — deleted
- `use Freyr\MessageBroker\Stamp\MessageIdStamp` — deleted (no longer referenced)

### Test Changes

#### `WireFormatSerializerTest.php`

| Test | Change |
|------|--------|
| `testEncodeAddsMessageIdHeader` | **Delete** — `X-Message-Id` no longer produced |
| `testEncodeStripsStampHeaders` | **Replace** with `testEncodePreservesStampHeaders` — assert stamps ARE present |
| `testEncodeThrowsWhenMessageIdStampMissing` | **Delete** — serialiser no longer validates MessageIdStamp |
| `testDecodeRestoresMessageIdStamp` | **Update** — stamp now comes from native `X-Message-Stamp-*` header in round-trip |
| All other encode tests | **Update** — `createStampedEnvelope()` still needs both stamps (for round-trip), but assertions change |

#### `FunctionalTestCase.php` helpers

| Method | Change |
|--------|--------|
| `publishTestEvent()` | Replace `'X-Message-Id' => $messageId` with native stamp header |
| `publishOrderPlacedEvent()` | Same replacement |
| `publishMalformedAmqpMessage()` | Replace `X-Message-Id` with `X-Message-Stamp-*` format for `missingMessageId` and `invalidUuid` options |

**Native stamp header format:**

```php
'X-Message-Stamp-Freyr\MessageBroker\Stamp\MessageIdStamp' => json_encode([['messageId' => $messageId]]),
```

#### Functional test files (assertions only)

These files call the helpers above — most need no code changes beyond what the helper change provides. Tests that directly assert on `X-Message-Id` need updating:

- `OutboxFlowTest.php` — assert `X-Message-Stamp-*` instead of `X-Message-Id`
- `InboxFlowTest.php` — assert stamp on envelope, not header
- `InboxSerializerDebugTest.php` — update or remove (tests X-Message-Id → stamp flow)
- `InboxHeaderDebugTest.php` — update to expect native stamp headers

## Acceptance Criteria

- [x] `WireFormatSerializer` has no `MESSAGE_ID_HEADER` constant
- [x] `WireFormatSerializer.encode()` does not strip `X-Message-Stamp-*` headers
- [x] `WireFormatSerializer.decode()` does not re-inject `X-Message-Stamp-*` headers
- [x] `InboxSerializer` has no `MESSAGE_ID_HEADER` constant
- [x] `InboxSerializer.decode()` does not strip or create `MessageIdStamp`
- [x] `InboxSerializer.decode()` still creates `MessageNameStamp` (for retry)
- [x] `InboxSerializer.encode()` does not strip `X-Message-Stamp-*` headers
- [x] All unit tests pass
- [x] All functional tests pass
- [x] Round-trip encode/decode preserves both stamps

## Implementation Order

1. **WireFormatSerializer** — simplify `encode()` and `decode()`, update unit tests
2. **InboxSerializer** — simplify `encode()` and `decode()`
3. **FunctionalTestCase helpers** — update `publishTestEvent()`, `publishOrderPlacedEvent()`, `publishMalformedAmqpMessage()`
4. **Functional test assertions** — update any tests that assert on `X-Message-Id` directly
5. **Documentation** — update CLAUDE.md wire format section and README

## References

- Brainstorm: `docs/brainstorms/2026-02-13-native-stamp-headers-brainstorm.md`
- Previous plan: `docs/plans/2026-02-13-refactor-stamp-first-serializer-simplification-plan.md`
- Symfony `Serializer::decodeStamps()`: `vendor/symfony/messenger/Transport/Serialization/Serializer.php:124`
- Institutional learning: `docs/solutions/test-failures/phase-1-test-implementation-discoveries.md` — stamp headers MUST use full FQN
