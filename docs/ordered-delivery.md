# Ordered Outbox Delivery

Per-aggregate causal ordering for outbox events. Events sharing a partition key are delivered to AMQP in insertion order, whilst events with different partition keys are processed in parallel across workers.

## Quick Start

### 1. Change the outbox transport DSN

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            outbox:
                dsn: 'ordered-doctrine://default?table_name=messenger_outbox&queue_name=outbox'
                options:
                    auto_setup: true
```

### 2. Add PartitionKeyStampMiddleware to your bus

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - 'Freyr\MessageBroker\Outbox\MessageIdStampMiddleware'
                    - 'Freyr\MessageBroker\Outbox\PartitionKeyStampMiddleware'
                    - doctrine_transaction
                    - 'Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware'
```

### 3. Dispatch events with a partition key

```php
use Freyr\MessageBroker\Outbox\PartitionKeyStamp;

$this->bus->dispatch($orderPlaced, [
    new PartitionKeyStamp((string) $orderPlaced->orderId),
]);
```

## How It Works

The `OrderedOutboxTransport` uses a "head-of-line" SQL query:

```sql
SELECT m.* FROM messenger_outbox m
WHERE m.id IN (
    SELECT MIN(sub.id) FROM messenger_outbox sub
    WHERE sub.queue_name = ?
      AND (sub.delivered_at IS NULL OR sub.delivered_at < ?)
      AND sub.available_at <= ?
    GROUP BY sub.partition_key
)
LIMIT 1 FOR UPDATE SKIP LOCKED
```

This ensures only the oldest message per partition can be claimed by a worker. Workers parallelise across partitions but process each partition strictly in insertion order (by auto-increment `id`).

### Schema

The transport adds a `partition_key` column to the outbox table:

```sql
ALTER TABLE messenger_outbox
  ADD COLUMN partition_key VARCHAR(255) NOT NULL DEFAULT '';

CREATE INDEX idx_outbox_partition_order
  ON messenger_outbox (queue_name, partition_key, available_at, delivered_at, id);
```

With `auto_setup: true`, the transport creates the table automatically on first use.

## DSN Options

| Option | Default | Description |
|---|---|---|
| `table_name` | `messenger_messages` | Outbox table name |
| `queue_name` | `default` | Queue name filter |
| `redeliver_timeout` | `3600` | Seconds before a claimed message is eligible for redelivery |
| `auto_setup` | `true` | Automatically create the table if it does not exist |

## Edge Cases

| Scenario | Behaviour |
|---|---|
| No `PartitionKeyStamp` | `partition_key = ''` — no ordering constraint |
| Worker crash | Message redelivered after `redeliver_timeout` without stalling the partition |
| Hot partition | Bottlenecked to one worker at a time — by design |
| Empty outbox | `get()` returns empty; workers sleep via Symfony's backoff |

## Migration from Standard Outbox

1. Add the `partition_key` column (MySQL 8.0+ instant DDL):
   ```sql
   ALTER TABLE messenger_outbox
     ADD COLUMN partition_key VARCHAR(255) NOT NULL DEFAULT '',
     ALGORITHM=INPLACE, LOCK=NONE;
   ```
2. Add the covering index
3. Wait for the outbox to drain (or accept a temporary bottleneck — legacy rows with `partition_key = ''` are serialised one-at-a-time)
4. Change the DSN from `doctrine://` to `ordered-doctrine://`
5. Restart workers

## Limitations

- **No delayed outbox messages**: Messages with `available_at` in the future do not block subsequent messages in the same partition
- **Single worker per partition**: By design, only one worker processes a partition's head message
- **MySQL only**: The transport uses MySQL-specific `SKIP LOCKED` syntax
