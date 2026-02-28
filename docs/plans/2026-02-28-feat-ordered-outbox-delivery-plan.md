---
title: "feat: Ordered outbox delivery — per-aggregate causal consistency"
type: feat
date: 2026-02-28
issue: "#32"
brainstorm: docs/brainstorms/2026-02-28-outbox-ordered-delivery-brainstorm.md
review: Simplified after parallel review by DHH, Simplicity, and Architecture reviewers
---

# feat: Ordered Outbox Delivery — Per-Aggregate Causal Consistency

## Overview

Add per-aggregate causal ordering to the outbox pattern. With 5+ concurrent outbox workers using `SKIP LOCKED`, events for the same aggregate currently arrive at AMQP out of insertion order. This feature ensures events sharing a partition key are published to AMQP in strict insertion order, while maintaining full parallelism across different partitions.

Fixes #32

## Problem Statement

Symfony's Doctrine transport fetches messages with:

```sql
ORDER BY available_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED
```

With multiple workers, `SKIP LOCKED` lets Worker B publish event #2 for aggregate X before Worker A finishes event #1. Additionally, `available_at` has second-level precision with no `id` tiebreaker, so messages within the same second have undefined order.

## Proposed Solution

**4 new components** (simplified from 7 after review):

1. **`PartitionKeyStamp`** — carries the partition key value (e.g. aggregate ID)
2. **`PartitionKeyStampMiddleware`** — validates stamp presence at dispatch time (safety net)
3. **`OrderedOutboxTransport`** — custom transport with partition-aware query + `partition_key` column
4. **`OrderedOutboxTransportFactory`** — creates the transport from `ordered-doctrine://` DSN

**Removed after review:** `OutboxBus`, `OutboxBusInterface`, `message_broker.outbox.ordered` config flag.

### The Corrected Query

```sql
SELECT m.* FROM messenger_outbox m
WHERE m.id IN (
    SELECT MIN(sub.id)
    FROM messenger_outbox sub
    WHERE sub.queue_name = :queueName
      AND (sub.delivered_at IS NULL OR sub.delivered_at < :redeliverLimit)
      AND sub.available_at <= :now
    GROUP BY sub.partition_key
)
FOR UPDATE SKIP LOCKED
LIMIT 1
```

**Critical fix from SpecFlow analysis:** The subquery MUST include the `delivered_at < :redeliverLimit` condition. Without it, a crashed worker's message (with `delivered_at` set) would be permanently excluded, stalling the entire partition.

### The API

Standard Symfony Messenger dispatch with an explicit stamp:

```php
$this->bus->dispatch($orderPlaced, [
    new PartitionKeyStamp((string) $orderPlaced->orderId),
]);
```

No wrapper bus. Follows the same pattern as existing stamps (`MessageIdStamp`, `MessageNameStamp`).

### Dispatch Flow

```
Application → MessageBusInterface::dispatch(event, [PartitionKeyStamp])
  → MessageIdStampMiddleware (stamps UUID v7)
  → MessageNameStampMiddleware (stamps semantic name)
  → PartitionKeyStampMiddleware (validates stamp present on OutboxMessage)
  → doctrine_transaction
  → OrderedOutboxTransport::send()
    → extracts PartitionKeyStamp from Envelope (before serialisation)
    → serialises envelope
    → INSERT with partition_key column
  → COMMIT
```

### Consume Flow

```
messenger:consume outbox
  → OrderedOutboxTransport::get()
    → partition-aware query (head-of-line per partition)
    → UPDATE delivered_at
    → deserialise Envelope
  → OutboxPublishingMiddleware → AMQP (PartitionKeyStamp naturally stripped)
```

## Technical Approach

### Architecture Decision: Custom Transport (Not Extending @internal)

Symfony's `Connection` class is marked `@internal`. Extending it violates Symfony's backward compatibility promise. Instead, `OrderedOutboxTransport` is a **standalone transport** that:

- Implements `TransportInterface`, `SetupableTransportInterface`, and `KeepaliveReceiverInterface`
- Owns a DBAL connection directly
- Uses Symfony's `SerializerInterface` for envelope encoding/decoding
- Implements `send()`, `get()`, `ack()`, `reject()`, `setup()`, `keepalive()` independently
- MySQL only (matches project requirements)

### Stamp Extraction: From Envelope, Not Headers

The sender extracts `PartitionKeyStamp` from the `Envelope` object (before serialisation), not from serialised headers. This avoids coupling to Symfony's internal stamp serialisation format.

```php
public function send(Envelope $envelope): Envelope
{
    $partitionKey = $envelope->last(PartitionKeyStamp::class)?->partitionKey ?? '';
    $encoded = $this->serializer->encode($envelope);
    // INSERT with partition_key as a first-class column
}
```

### Opt-In via DSN Scheme (No Config Flag)

The feature is activated by changing the outbox transport DSN from `doctrine://` to `ordered-doctrine://`. No `message_broker.outbox.ordered` configuration flag is needed — the DSN scheme IS the opt-in mechanism.

The `OrderedOutboxTransportFactory` is always registered (inert unless the DSN matches). The `PartitionKeyStampMiddleware` is conditionally registered only when the ordered transport is detected (via a compiler pass that inspects the outbox transport DSN, or always registered since it only validates `OutboxMessage` envelopes with no `ReceivedStamp`).

### All Components in Main Package (Defer Contracts)

All new components live in `freyr/message-broker` initially:

| Component | Package | Rationale |
|---|---|---|
| `PartitionKeyStamp` | `freyr/message-broker` | Defer contracts release. Move later when a second consumer materialises. |
| `PartitionKeyStampMiddleware` | `freyr/message-broker` | Middleware — DI infrastructure |
| `OrderedOutboxTransport` | `freyr/message-broker` | Transport — depends on DBAL |
| `OrderedOutboxTransportFactory` | `freyr/message-broker` | Factory — depends on ManagerRegistry |

**No blocking contracts release required.** All work proceeds in a single repository.

### Schema Change

```sql
ALTER TABLE messenger_outbox
  ADD COLUMN partition_key VARCHAR(255) NOT NULL DEFAULT '';

CREATE INDEX idx_outbox_partition_order
  ON messenger_outbox (queue_name, partition_key, available_at, delivered_at, id);
```

Auto-setup (`setup()` method) creates the table with the `partition_key` column from the start. For existing tables, a manual migration adds the column.

### Index Design

The subquery needs an efficient `MIN(id) GROUP BY partition_key` with WHERE filters:

- **Recommended covering index:** `(queue_name, partition_key, available_at, delivered_at, id)`
- The existing Symfony index `(queue_name, available_at, delivered_at, id)` remains for the standard transport
- **Must benchmark:** Run `EXPLAIN ANALYZE` with 100k messages, 1000 partitions. The `OR` condition on `delivered_at` may prevent MySQL loose index scan optimisation.

---

## Implementation Phases

### Phase 1: PartitionKeyStamp + PartitionKeyStampMiddleware

- [x] `PartitionKeyStamp` — `final readonly class` implementing `StampInterface`, property `string $partitionKey`
- [x] `PartitionKeyStampMiddleware` — validates stamp presence at dispatch time
  - Follows existing middleware guard pattern (3 checks)
  - Only validates at dispatch time (skips when `ReceivedStamp` present)
  - Only validates `OutboxMessage` envelopes
  - Throws `\LogicException` if `OutboxMessage` lacks `PartitionKeyStamp`
- [x] Unit tests for stamp (trivial)
- [x] Unit tests for middleware following `MessageIdStampMiddlewareTest` pattern:
  - `testOutboxMessageWithoutPartitionKeyStampThrows`
  - `testOutboxMessageWithPartitionKeyStampPassesThrough`
  - `testNonOutboxMessagePassesThroughWithoutValidation`
  - `testOutboxMessageWithReceivedStampSkipsValidation`

#### src/Outbox/PartitionKeyStamp.php

```php
final readonly class PartitionKeyStamp implements StampInterface
{
    public function __construct(public string $partitionKey) {}
}
```

#### src/Outbox/PartitionKeyStampMiddleware.php

```php
final readonly class PartitionKeyStampMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof OutboxMessage) {
            return $stack->next()->handle($envelope, $stack);
        }
        if ($envelope->last(ReceivedStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }
        if ($envelope->last(PartitionKeyStamp::class) === null) {
            throw new \LogicException(sprintf(
                'OutboxMessage "%s" must have a PartitionKeyStamp. '
                . 'Dispatch with: $bus->dispatch($event, [new PartitionKeyStamp($key)])',
                $envelope->getMessage()::class
            ));
        }
        return $stack->next()->handle($envelope, $stack);
    }
}
```

---

### Phase 2: OrderedOutboxTransport

The core of the feature. Standalone transport implementation (~150-200 lines).

- [x] Implements `TransportInterface`, `SetupableTransportInterface`, `KeepaliveReceiverInterface`
- [x] `send()` — serialises envelope, extracts `PartitionKeyStamp`, INSERTs with `partition_key` column
- [x] `get()` — partition-aware head-of-line query with redelivery condition, deserialises envelope
- [x] `ack()` — DELETEs row by ID
- [x] `reject()` — DELETEs row by ID
- [x] `setup()` — creates table with `partition_key` column, indexes, and `BIGINT AUTO_INCREMENT` id
- [x] `keepalive()` — refreshes `delivered_at` to prevent false redeliver timeout
- [x] Unit tests for each method (mocked DBAL connection)

#### src/Outbox/Transport/OrderedOutboxTransport.php

```php
final class OrderedOutboxTransport implements TransportInterface, SetupableTransportInterface, KeepaliveReceiverInterface
{
    public function __construct(
        private readonly DBALConnection $connection,
        private readonly SerializerInterface $serializer,
        private readonly string $tableName,
        private readonly string $queueName,
        private readonly int $redeliverTimeout = 3600,
        private bool $autoSetup = false,
    ) {}

    public function send(Envelope $envelope): Envelope { ... }
    public function get(): iterable { ... }
    public function ack(Envelope $envelope): void { ... }
    public function reject(Envelope $envelope): void { ... }
    public function setup(): void { ... }
    public function keepalive(Envelope $envelope): void { ... }
}
```

##### send() — Extract stamp before serialisation

```php
public function send(Envelope $envelope): Envelope
{
    $partitionKey = $envelope->last(PartitionKeyStamp::class)?->partitionKey ?? '';
    $encoded = $this->serializer->encode($envelope);
    $now = new \DateTimeImmutable();

    $this->connection->insert($this->tableName, [
        'body' => $encoded['body'],
        'headers' => json_encode($encoded['headers'] ?? []),
        'queue_name' => $this->queueName,
        'created_at' => $now,
        'available_at' => $now,
        'partition_key' => $partitionKey,
    ], [/* datetime types */]);

    $id = $this->connection->lastInsertId();

    return $envelope->with(new TransportMessageIdStamp($id));
}
```

##### get() — Head-of-line per partition

```php
public function get(): iterable
{
    $this->connection->beginTransaction();
    try {
        $now = new \DateTimeImmutable();
        $redeliverLimit = $now->modify(sprintf('-%d seconds', $this->redeliverTimeout));

        $sql = sprintf(
            'SELECT m.* FROM %s m '
            . 'WHERE m.id IN ('
            . '  SELECT MIN(sub.id) FROM %s sub'
            . '  WHERE sub.queue_name = :queueName'
            . '    AND (sub.delivered_at IS NULL OR sub.delivered_at < :redeliverLimit)'
            . '    AND sub.available_at <= :now'
            . '  GROUP BY sub.partition_key'
            . ') FOR UPDATE SKIP LOCKED LIMIT 1',
            $this->tableName,
            $this->tableName,
        );

        $row = $this->connection->fetchAssociative($sql, [
            'queueName' => $this->queueName,
            'redeliverLimit' => $redeliverLimit,
            'now' => $now,
        ], [/* datetime types */]);

        if ($row === false) {
            $this->connection->commit();
            return [];
        }

        $this->connection->update(
            $this->tableName,
            ['delivered_at' => $now],
            ['id' => $row['id']],
            [/* datetime type */],
        );
        $this->connection->commit();
    } catch (\Throwable $e) {
        $this->connection->rollBack();
        throw $e;
    }

    $envelope = $this->serializer->decode([
        'body' => $row['body'],
        'headers' => json_decode($row['headers'], true),
    ]);

    yield $envelope->with(new TransportMessageIdStamp((string) $row['id']));
}
```

##### keepalive() — Prevent false redeliver timeout

```php
public function keepalive(Envelope $envelope): void
{
    $stamp = $envelope->last(TransportMessageIdStamp::class);
    if ($stamp === null) {
        return;
    }
    $this->connection->update(
        $this->tableName,
        ['delivered_at' => new \DateTimeImmutable()],
        ['id' => $stamp->getId()],
        [/* datetime type */],
    );
}
```

---

### Phase 3: OrderedOutboxTransportFactory + Service Registration

- [x] `OrderedOutboxTransportFactory` implementing `TransportFactoryInterface`
- [x] Supports `ordered-doctrine://` DSN scheme
- [x] Parses DSN for connection name, table name, queue name, redeliver timeout, auto_setup
- [x] Creates `OrderedOutboxTransport` with resolved DBAL connection
- [x] Register factory in `config/services.yaml` with `messenger.transport_factory` tag
- [x] Register `PartitionKeyStampMiddleware` in `config/services.yaml`
- [x] Update recipe `messenger.yaml` with commented `ordered-doctrine://` DSN option
- [x] Unit tests for factory

#### src/Outbox/Transport/OrderedOutboxTransportFactory.php

```php
final readonly class OrderedOutboxTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private ManagerRegistry $registry,
    ) {}

    public function createTransport(
        #[\SensitiveParameter] string $dsn,
        array $options,
        SerializerInterface $serializer,
    ): TransportInterface {
        // Parse DSN: ordered-doctrine://default?table_name=messenger_outbox&queue_name=outbox
        // Resolve DBAL connection from registry
        // Return new OrderedOutboxTransport(...)
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'ordered-doctrine://');
    }
}
```

#### config/services.yaml additions

```yaml
# Ordered Outbox Transport
Freyr\MessageBroker\Outbox\Transport\OrderedOutboxTransportFactory:
    arguments:
        $registry: '@doctrine'
    tags:
        - { name: 'messenger.transport_factory' }

Freyr\MessageBroker\Outbox\PartitionKeyStampMiddleware:
    tags:
        - { name: 'messenger.middleware' }
```

#### recipe update — messenger.yaml (commented option)

```yaml
# To enable per-aggregate ordered delivery, change doctrine:// to ordered-doctrine://
# outbox:
#     dsn: 'ordered-doctrine://default?table_name=messenger_outbox&queue_name=outbox'
```

---

### Phase 4: Functional Tests

- [ ] `OrderedOutboxTransportTest` — against real MySQL
  - `testSendStoresPartitionKey` — verify partition_key column populated
  - `testGetReturnsOldestMessagePerPartition` — verify head-of-line ordering
  - `testGetSkipsLockedPartitionHeads` — verify SKIP LOCKED with concurrent connections
  - `testAckDeletesRow` — verify ack behaviour
  - `testRejectDeletesRow` — verify reject behaviour
  - `testSetupCreatesTableWithPartitionKeyColumn` — verify schema
  - `testRedeliveryTimeoutReleasesMessage` — verify crashed worker recovery
  - `testEmptyPartitionKeyTreatedAsIndependent` — verify empty key behaviour
  - `testKeepaliveRefreshesDeliveredAt` — verify keepalive behaviour
- [ ] Add outbox table schema to functional test setup (`tests/Functional/schema.sql`)
- [ ] Run `EXPLAIN ANALYZE` against the head-of-line query with test data

---

### Phase 5: Documentation

- [ ] Update `docs/database-schema.md` — add partition_key column docs
- [ ] Create `docs/ordered-delivery.md` — full guide with examples
- [ ] Update `CLAUDE.md` — add ordered outbox architecture section
- [ ] Update `README.md` — mention ordered delivery feature
- [x] Update recipe comments

---

## Alternative Approaches Considered

### OutboxBus Wrapper (Removed after review)

A wrapper around `MessageBusInterface` with `dispatch(OutboxMessage $event, string $partitionKey)`.

**Removed:** The wrapper is one line of code. The middleware already validates at runtime. Applications should use standard Symfony dispatch with an explicit stamp. No need for a new abstraction that fights Symfony conventions.

### Config Flag `outbox.ordered` (Removed after review)

A bundle configuration flag to enable ordered delivery.

**Removed:** The `ordered-doctrine://` DSN scheme is the opt-in mechanism. One switch, not two.

### Advisory Lock Guard in Middleware (Rejected)

**Rejected:** Retry overhead under load, potential starvation with 5+ workers.

### Partition-Assigned Workers / Kafka-Style (Rejected)

**Rejected:** Operational burden, uneven load distribution.

### Extend @internal Connection (Rejected)

**Rejected:** Violates Symfony's backward compatibility promise.

## Acceptance Criteria

### Functional Requirements

- [ ] Events dispatched with the same partition key arrive at AMQP in insertion order
- [ ] Events with different partition keys are processed in parallel across workers
- [ ] `PartitionKeyStampMiddleware` throws `LogicException` if `OutboxMessage` lacks `PartitionKeyStamp`
- [ ] Crashed worker's message is eventually redelivered (redeliver timeout) without stalling the partition
- [x] `keepalive()` refreshes `delivered_at` to prevent false redeliver timeout during long operations
- [ ] Empty partition key (`''`) means no ordering constraint
- [ ] Auto-setup creates the outbox table with `partition_key` column
- [ ] Feature is opt-in via `ordered-doctrine://` DSN; `doctrine://` continues to work as before
- [ ] Package is backward compatible — no changes required unless opting in

### Non-Functional Requirements

- [ ] Head-of-line query performs acceptably with 100k messages, 1000 partitions (< 50ms)
- [ ] `EXPLAIN ANALYZE` verifies covering index usage
- [ ] No additional database round trips compared to standard transport (one SELECT + UPDATE per poll)
- [ ] PartitionKeyStamp is NOT leaked to AMQP (stripped by OutboxPublishingMiddleware whitelist)

### Quality Gates

- [ ] All unit tests pass (TDD — tests written first)
- [ ] Functional tests against real MySQL pass
- [ ] PHPStan passes at current level
- [ ] ECS code style passes
- [ ] CLAUDE.md conventions followed (final readonly where applicable, British English, separate use imports)

## Dependencies & Prerequisites

1. **MySQL 8.0+** — required for `SKIP LOCKED` support (already a requirement)
2. **Symfony Messenger ^6.4|^7.0** — for `TransportFactoryInterface`
3. **No contracts release required** — all components in main package initially

## Risk Analysis & Mitigation

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Subquery performance with 100k+ messages | High | Medium | Covering index + `EXPLAIN ANALYZE` benchmark before release |
| Hot partition bottleneck (one aggregate dominates) | Medium | Medium | By design — document as expected behaviour |
| Symfony transport API changes | Medium | Low | Standalone transport, no @internal dependency |
| Migration with in-flight messages | Medium | High | Document cutover; legacy rows with `partition_key = ''` are serialised one-at-a-time (temporary bottleneck) |
| Worker starvation (idle workers with few partitions) | Low | Medium | Symfony's built-in sleep backoff handles this |
| Long AMQP publish triggering redeliver timeout | Medium | Low | `KeepaliveReceiverInterface` implementation prevents this |

## Migration / Cutover Procedure

For existing production systems:

1. **Deploy schema migration** (while old workers run — MySQL 8.0+ instant DDL):
   ```sql
   ALTER TABLE messenger_outbox
     ADD COLUMN partition_key VARCHAR(255) NOT NULL DEFAULT '',
     ALGORITHM=INPLACE, LOCK=NONE;
   CREATE INDEX idx_outbox_partition_order
     ON messenger_outbox (queue_name, partition_key, available_at, delivered_at, id);
   ```
2. **Wait for outbox to drain** (recommended) or accept temporary bottleneck:
   - Legacy rows with `partition_key = ''` are all in one partition
   - They will be processed one-at-a-time until drained
   - New rows dispatched with the ordered transport have real partition keys
3. **Deploy new code** with DSN change to `ordered-doctrine://`
4. **Restart workers** — they now use `OrderedOutboxTransport`
5. **Verify** — monitor partition distribution and processing times

## Constraints and Limitations

- **No delayed outbox messages:** The `available_at` filter in the subquery means delayed messages do not block subsequent messages in the same partition. Outbox messages should always be available immediately.
- **Single worker per partition at a time:** By design, only one worker processes a partition's head message. This limits per-partition throughput to one worker.
- **LIMIT 1 per poll:** Each worker claims one partition head per poll cycle. If that row is already locked, `SKIP LOCKED` moves to the next available head.

## References & Research

### Internal References

- Brainstorm: `docs/brainstorms/2026-02-28-outbox-ordered-delivery-brainstorm.md`
- Existing middleware pattern: `src/Outbox/MessageIdStampMiddleware.php`
- Existing stamp pattern: `vendor/freyr/message-broker-contracts/src/MessageIdStamp.php`
- OutboxPublishingMiddleware: `src/Outbox/OutboxPublishingMiddleware.php:77` (stamp whitelist)
- Doctrine Connection (vendor): `vendor/symfony/doctrine-messenger/Transport/Connection.php`
- Transport factory (vendor): `vendor/symfony/doctrine-messenger/Transport/DoctrineTransportFactory.php`
- Test patterns: `tests/Unit/MiddlewareStackFactory.php`, `tests/Unit/Outbox/MessageIdStampMiddlewareTest.php`
- Critical patterns: `docs/solutions/patterns/critical-patterns.md`
- Database schema docs: `docs/database-schema.md`

### Institutional Learnings Applied

- `messenger_outbox.id` MUST be `BIGINT AUTO_INCREMENT` (Symfony requirement) — `docs/solutions/database-issues/migration-schema-mismatch-ci-vs-local.md`
- Middleware tagged with `messenger.middleware` IS auto-registered into bus — use `priority` for ordering — `docs/solutions/test-failures/doctrine-transaction-middleware-orm-configuration.md`
- Full namespace required for stamp headers — `docs/solutions/test-failures/phase-1-test-implementation-discoveries.md`
- Schema setup in `setUpBeforeClass()`, check table existence before truncating — `docs/solutions/ci-issues/hidden-schema-failures-fresh-environment.md`

### Related Work

- Issue: #32
- Brainstorm: `docs/brainstorms/2026-02-28-outbox-ordered-delivery-brainstorm.md`
