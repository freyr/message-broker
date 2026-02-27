---
topic: Ordered outbox delivery to AMQP
date: 2026-02-28
status: decided
---

# Ordered Outbox Delivery to AMQP

## Problem

With multiple outbox workers (5+), events for the same aggregate can arrive at AMQP out of insertion order. Symfony's Doctrine transport uses `ORDER BY available_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED` — `SKIP LOCKED` lets Worker B publish event #2 before Worker A finishes event #1 for the same aggregate.

**Requirement:** Per-aggregate causal consistency. Events for the same aggregate must reach AMQP in the same order they were dispatched to the outbox table.

## What We're Building

A **partition-aware ordered outbox transport** that:

1. Adds a `partition_key` column to the outbox table
2. Uses a "head-of-line only" query so only the oldest message per partition can be locked
3. Exposes an `OutboxBus` wrapper that enforces partition key at compile time

### The Query

```sql
SELECT m.* FROM messenger_outbox m
WHERE m.id IN (
    SELECT MIN(sub.id)
    FROM messenger_outbox sub
    WHERE sub.queue_name = 'outbox'
      AND sub.delivered_at IS NULL
      AND sub.available_at <= NOW()
    GROUP BY sub.partition_key
)
FOR UPDATE SKIP LOCKED
LIMIT 1
```

Workers parallelise across partitions but process each partition strictly in insertion order (by auto-increment `id`).

### The API

```php
// Application injects OutboxBus instead of MessageBusInterface
final readonly class OutboxBus
{
    public function __construct(private MessageBusInterface $bus) {}

    public function dispatch(OutboxMessage $event, string $partitionKey): Envelope
    {
        return $this->bus->dispatch($event, [
            new PartitionKeyStamp($partitionKey),
        ]);
    }
}

// Usage:
$this->outboxBus->dispatch($orderPlaced, (string) $orderPlaced->orderId);
```

## Why This Approach

### Approach A: Custom Ordered Outbox Transport (chosen)

SQL-level guarantee via partition-aware query. The `MIN(id) GROUP BY partition_key` subquery ensures only head-of-line messages are claimable. Full parallelism across partitions, strict FIFO within each.

**Pros:** Strongest guarantee, no retry overhead, scales with worker count, clean separation
**Cons:** Custom transport code, schema migration, replaces vendor receiver

### Approach B: Advisory Lock Guard in Middleware (rejected)

Middleware acquires advisory lock on partition key, checks for older messages, throws `RecoverableMessageHandlingException` to retry if out of order.

**Rejected because:** Retry overhead under load, potential starvation, advisory lock management complexity. Not suitable for 5+ workers with high throughput.

### Approach C: Partition-Assigned Workers (rejected)

Hash partition key to N buckets, statically assign workers to buckets.

**Rejected because:** Operational burden (static assignment), uneven load distribution, requires worker coordination/configuration.

## Key Decisions

1. **Ordering scope:** Per-aggregate (partition key), not global
2. **Enforcement point:** At outbox publish time (this package's responsibility)
3. **API design:** `OutboxBus` wrapper with compile-time enforcement — `dispatch(OutboxMessage $event, string $partitionKey)`. Application code injects `OutboxBus` instead of `MessageBusInterface`.
4. **No attribute:** Partition key is passed explicitly at dispatch time, not declared via PHP attribute. This gives maximum flexibility (computed keys, keys from external context) with compile-time safety.
5. **Transport:** Custom receiver replaces Symfony's Doctrine transport `get()` with partition-aware query
6. **Schema:** New `partition_key VARCHAR(255) NOT NULL DEFAULT ''` column + index `(partition_key, id)`
7. **Default behaviour:** Events without partition key (empty string) have no ordering constraint — any worker can claim

## New Components

| Component | Follows pattern of |
|---|---|
| `PartitionKeyStamp` | `MessageIdStamp`, `MessageNameStamp` |
| `PartitionKeyStampMiddleware` | `MessageIdStampMiddleware` (validates stamp presence) |
| `OutboxBus` | New — wraps `MessageBusInterface` |
| `OrderedOutboxReceiver` | Decorator over Doctrine transport receiver |
| `OrderedOutboxSender` | Decorator over Doctrine transport sender (stores `partition_key` column) |
| Schema migration | Existing `docs/database-schema.md` pattern |

## Edge Cases

| Scenario | Behaviour |
|---|---|
| Event dispatched without `OutboxBus` | Middleware throws `\LogicException` if `OutboxMessage` lacks `PartitionKeyStamp` |
| Worker crashes mid-processing | `delivered_at` timeout (3600s) releases the lock |
| Very hot partition (one aggregate dominates) | Bottlenecked to one worker at a time — by design |
| Empty partition key | No ordering constraint, treated as independent messages |

## Open Questions

1. Should `OutboxBus` live in the contracts package or the main package?
2. Should the custom transport be opt-in (configuration flag) or replace the default?
3. Performance testing needed: how does the `MIN(id) GROUP BY` subquery perform with 100k+ messages?
4. Should there be a way to opt out of ordering for specific high-throughput, order-insensitive events?
