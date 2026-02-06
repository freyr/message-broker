---
title: "fix: Correct recipe serialiser class references"
type: fix
date: 2026-02-06
severity: critical
source: docs/reviews/2026-02-06-architectural-review.md (Finding #1)
---

# fix: Correct recipe serialiser class references

## Overview

The Symfony Flex recipe at `recipe/1.0/config/packages/messenger.yaml` references a non-existent class `Freyr\MessageBroker\Serializer\MessageNameSerializer` on **two transports** (lines 24 and 37). Additionally, `recipe/1.0/config/packages/message_broker.yaml` mentions the same ghost class in a comment. Any user installing the bundle via Flex gets an immediately broken configuration that fails at runtime when the serialiser service cannot be resolved.

## Problem Statement

### What is broken

The recipe's `messenger.yaml` configures two transports with a serialiser class that does not exist:

```yaml
# recipe/1.0/config/packages/messenger.yaml — line 24
outbox:
    serializer: 'Freyr\MessageBroker\Serializer\MessageNameSerializer'  # DOES NOT EXIST

# recipe/1.0/config/packages/messenger.yaml — line 37
amqp:
    serializer: 'Freyr\MessageBroker\Serializer\MessageNameSerializer'  # DOES NOT EXIST
```

The actual classes are:
- `Freyr\MessageBroker\Serializer\OutboxSerializer` — used for **publishing** (outbox → AMQP)
- `Freyr\MessageBroker\Serializer\InboxSerializer` — used for **consumption** (AMQP → handlers)

### Why it matters

1. **Immediate breakage** — `composer require freyr/message-broker` produces a non-functional app
2. **First impression** — users who install via Flex and hit this error may abandon the package
3. **Subtle mismatch** — the outbox Doctrine transport should **not** have a custom serialiser at all (see below)

### Root cause

The recipe was likely written before the serialiser was split into `InboxSerializer` and `OutboxSerializer`. The placeholder name `MessageNameSerializer` was never updated.

## Proposed Solution

### Transport serialiser assignments

Understanding which serialiser goes where is critical. The architecture has **four transports** with different serialisation needs:

| Transport | Direction | Serialiser | Reason |
|-----------|-----------|------------|--------|
| `outbox` (Doctrine) | Internal: event bus → DB table | **None (default)** | Messages are PHP objects stored by Doctrine transport's native serialiser. `OutboxToAmqpBridge` consumes them — no semantic name needed at this stage. |
| `amqp` (publish) | Internal → external: bridge → RabbitMQ | `OutboxSerializer` | Translates FQN → semantic name in `type` header when publishing to AMQP. |
| `amqp_orders` (consume) | External → internal: RabbitMQ → handlers | `InboxSerializer` | Translates semantic name → FQN when consuming from AMQP. |
| `failed` (Doctrine) | Internal: failed message storage | **None (default)** | Standard Symfony failed transport. |

**Key insight:** The test configuration at `tests/Functional/config/test.yaml` already has the correct assignments (lines 23, 34, 48). The recipe should mirror these.

### Changes required

#### 1. `recipe/1.0/config/packages/messenger.yaml`

**Line 24** — Remove custom serialiser from `outbox` transport:
```yaml
# BEFORE
outbox:
    dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
    serializer: 'Freyr\MessageBroker\Serializer\MessageNameSerializer'

# AFTER
outbox:
    dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
    # No custom serializer needed - messages have native PHP types
    # OutboxToAmqpBridge consumes from this transport
```

**Line 37** — Fix serialiser reference on `amqp` transport:
```yaml
# BEFORE
amqp:
    dsn: '%env(MESSENGER_AMQP_DSN)%'
    serializer: 'Freyr\MessageBroker\Serializer\MessageNameSerializer'

# AFTER
amqp:
    dsn: '%env(MESSENGER_AMQP_DSN)%'
    serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
```

**Add inbox consumption transport** (currently missing from recipe):
```yaml
# Example AMQP consumption transport
# Duplicate and rename for each queue your application consumes from
amqp_orders:
    dsn: '%env(MESSENGER_AMQP_DSN)%'
    serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'
    options:
        auto_setup: false
        queue:
            name: 'orders_queue'
    retry_strategy:
        max_retries: 3
        delay: 1000
        multiplier: 2
```

#### 2. `recipe/1.0/config/packages/message_broker.yaml`

**Line 4** — Fix comment referencing `MessageNameSerializer`:
```yaml
# BEFORE
# Used by MessageNameSerializer to translate semantic names to FQN during deserialization

# AFTER
# Used by InboxSerializer to translate semantic names to FQN during deserialisation
```

#### 3. `recipe/1.0/config/packages/messenger.yaml` comments

**Lines 57-60** — Fix comment referencing `MessageNameSerializer`:
```yaml
# BEFORE
# Messages are deserialized by MessageNameSerializer into typed objects

# AFTER
# Messages are deserialised by InboxSerializer into typed objects
```

## Acceptance Criteria

- [x] Recipe `messenger.yaml` `outbox` transport has **no** custom serialiser (uses Symfony default)
- [x] Recipe `messenger.yaml` `amqp` transport references `OutboxSerializer`
- [x] Recipe `messenger.yaml` includes an example `amqp_orders` consumption transport with `InboxSerializer`
- [x] Recipe `message_broker.yaml` comment references `InboxSerializer`, not `MessageNameSerializer`
- [x] Recipe `messenger.yaml` inline comments reference correct class names
- [x] No references to `MessageNameSerializer` remain anywhere in the repository
- [x] All existing functional tests still pass (they use `test.yaml`, not the recipe)

## Verification

After making changes, verify no ghost references remain:

```bash
grep -r "MessageNameSerializer" . --include="*.yaml" --include="*.php" --include="*.md"
```

The only acceptable result should be the review document itself (`docs/reviews/2026-02-06-architectural-review.md`) and this plan file.

## Risk Analysis

**Risk: Low**

- Recipe files are not loaded by tests — changes cannot break existing test suite
- The recipe is not yet published to symfony/recipes-contrib — no deployed users affected
- The fix is a pure configuration correction with no code changes

**Out of scope:**
- Splitting/refactoring serialiser classes (separate issue)
- Adding recipe integration tests (separate issue)

## References

- Review finding: `docs/reviews/2026-02-06-architectural-review.md` (Finding #1, Critical)
- Correct test config: `tests/Functional/config/test.yaml` (lines 23, 34, 48)
- Bundle services: `config/services.yaml` (lines 32-43)
- `InboxSerializer`: `src/Serializer/InboxSerializer.php`
- `OutboxSerializer`: `src/Serializer/OutboxSerializer.php`

Part of #3
