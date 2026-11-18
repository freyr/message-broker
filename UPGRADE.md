# Upgrade guide

## From 0.8.x to 0.9.0

This release adds the abandon mark of an outbox row: a new column, two new
methods of the `OutboxStore` port and two new commands. Change the schema
first, then deploy the code.

### Migration of `outbox_messages`

The new code needs the column `outbox_messages.abandoned_at`. The code of
0.8.x works with the column present, because its insert names its columns.
Run these steps in this order:

1. Stop the scheduled `message-broker:outbox:maintain`, and start it again
   only after step 2. A run of the 0.8.x code between the steps can detach an
   expired partition and attach it again after the `ADD COLUMN`; that
   `ATTACH` fails, and the table stays outside the parent with its unpublished
   rows. Then run `message-broker:outbox:maintain` once by hand. It finishes a
   pending detach, and it drops or attaches again a detached daily table. Then
   check that no daily table is outside the parent. `ALTER TABLE` on the
   parent changes only the attached partitions, and a later `ATTACH` of a
   table with no `abandoned_at` fails:

   ```sql
   SELECT c.relname FROM pg_class c
   WHERE c.relkind = 'r' AND c.relname ~ '^outbox_messages_p[0-9]{8}$'
     AND NOT EXISTS (SELECT 1 FROM pg_inherits i
                     WHERE i.inhrelid = c.oid AND i.inhparent = 'outbox_messages'::regclass);
   ```

   For each daily table that the check shows, run:

   ```sql
   ALTER TABLE "<name>" ADD COLUMN abandoned_at TIMESTAMP(3) NULL;
   ```

2. Add the column to the parent. With no default, it needs no table rewrite,
   and it applies to all attached partitions. It takes `ACCESS EXCLUSIVE` on
   the parent and on each partition for a short time, and it waits for the
   open transactions on the table; producers wait behind it. Set
   `lock_timeout`, and repeat the step on a timeout:

   ```sql
   SET lock_timeout = '5s';
   ALTER TABLE outbox_messages ADD COLUMN abandoned_at TIMESTAMP(3) NULL;
   ```

   Then repeat the check of step 1 for daily tables outside the parent, and
   start the scheduled `outbox:maintain` again.
3. Deploy the new code. The schema change comes first: the new code fails
   with no column.
4. No index change: `idx_outbox_pending` keeps its predicate
   `published_at IS NULL`. A new installation gets the column from
   `message-broker:setup:schema`.

pg_partman takes the columns of a new partition from the parent, so its
configuration needs no change.

To roll back to 0.8.x, deploy the old code; it runs with the column present.
It does not read `abandoned_at`, so its relay claims an abandoned row again.
Before you drop the column, list those rows with
`SELECT id FROM outbox_messages WHERE abandoned_at IS NOT NULL`. Drop it only
when the rollback is final: `ALTER TABLE outbox_messages DROP COLUMN
abandoned_at` (a short `ACCESS EXCLUSIVE` lock; set `lock_timeout`).

### New methods of `OutboxStore`

An implementation of `Outbox\OutboxStore` outside this library must add two
methods (see `docs/core/PORTS.md`):

```php
public function abandon(string $id): int;

/** @return list<FailingRow> */
public function failing(string $lane, int $minAttempts, int $limit): array;
```

- `abandon()` sets `abandoned_at` on each row of the message ID that is not
  published and not abandoned, and returns the count (0 for an unknown ID).
- `failing()` returns the open rows of a lane with `attempts >= $minAttempts`,
  by attempts (highest first) and then by age (oldest first), at most
  `$limit`. It throws `InvalidArgumentException` for a value below 1.
- The claim and the outcome writes of `drainClaimed()` must skip an
  abandoned row.

Extend `OutboxStoreContractTestCase` to check your implementation; it has new
cases for both methods. The record helper of the test case takes two new
optional parameters, `id` and `createdAt`.

### New text of `OutboxRetention`

"Unpublished" now means `published_at IS NULL AND abandoned_at IS NULL`. An
abandoned row does not keep its storage unit. A third-party implementation of
`Outbox\OutboxRetention` must follow this text, and it reports the abandoned
rows of each dropped unit in the new last parameter of `RetentionReport`,
`$abandoned`.

## From 0.7.x to 0.8.0

This release changes the default behavior of the consumer. The database
schema and the wire format do not change.

### Delivery limit

`DeliveryProcessor` has a delivery limit of 5 by default (the new last
constructor parameter `maxDeliveries`).

- On a quorum queue, the sixth delivery after five deliveries that ended with
  no ack becomes a dead letter with the error `Consumer\DeliveryLimitExceeded`,
  and the handler does not run.
- Pass `maxDeliveries: null` to keep the old behavior.
- Keep the value not higher than `x-delivery-limit` of the queue (a lower value
  is recommended). When you set `x-delivery-limit` below 5, lower
  `maxDeliveries` too.
- A message whose `x-delivery-count` is already at or above `maxDeliveries` at
  the upgrade becomes a dead letter at its next delivery (the row is
  replayable). To prevent this, set `maxDeliveries` to `null` or to a higher
  value for the first run.

```php
$processor = new DeliveryProcessor(
    // ... the other arguments do not change
    maxDeliveries: null, // the behavior of 0.7.x: no delivery limit
);
```

### The stop of `AmqpConsumer`

- `AmqpConsumer` returns its unacked deliveries at a stop: a graceful stop
  adds no count, and a failure adds 1 to the delivery in work only. It sends
  `basic.cancel`, `basic.reject` (only for the delivery in work, after a
  failure) and `basic.nack` (tag 0, `multiple`, `requeue`). See
  [docs/amqp/CONSUMER.md](docs/amqp/CONSUMER.md), *The return rule*.
- A confirm timeout of the wait-queue publish ends `run()` with the exception,
  so `message-broker:consume` exits with a failure. In the earlier release it
  ended `run()` as an idle stop, with exit code 0.
- `AmqpConsumer` takes an optional `logger` (the new last constructor
  parameter, a PSR-3 `LoggerInterface`). It receives a failure inside the
  return.

### Retry policy

`ExponentialRetryPolicy` takes the optional `deadLetterOn` and `discardOn`
lists of throwable classes (empty by default, so nothing changes when you do
not pass them). See [docs/core/CONSUMER.md](docs/core/CONSUMER.md), *Error
class lists*.

## From 0.6.x to 0.7.0

This release has no backward-compatibility aliases. Change your `use` statements and your wiring code as shown below.
The database schema, the wire format and the behavior of the relay and of the consumer do not change.

### New layout

The code is in three folders. Each folder has its own namespace and its own PSR-4 root:

| Package | Folder | Namespace |
|---|---|---|
| core | `src/broker/` | `Freyr\MessageBroker\` |
| PostgreSQL adapter | `src/adapter/postgresql/` | `Freyr\MessageBroker\Adapter\PostgreSql\` |
| AMQP adapter | `src/adapter/amqp/` | `Freyr\MessageBroker\Adapter\Amqp\` |

The adapter namespaces are flat: a class is directly in the adapter namespace.
The namespace `Freyr\MessageBroker\Transport` does not exist any more.
The package is still one Composer package, `freyr/message-broker`. You do not change `composer.json`.

The core has no PDO class, no `PhpAmqpLib` class and no adapter class in its code.
An adapter uses the core and its own package only. It never uses the other adapter.

### Renamed, moved and removed classes

Each row is one public class of 0.6.0 that has a new name or a new namespace.
All new names are relative to `Freyr\MessageBroker\`.

| Old class (0.6.0) | New class |
|---|---|
| `Outbox\PdoOutboxStore` | `Adapter\PostgreSql\PostgreSqlOutboxStore` |
| `Outbox\OutboxPartitionManager` | `Adapter\PostgreSql\PostgreSqlOutboxRetention` |
| `Outbox\PartitionPurgeReport` | `Outbox\RetentionReport` |
| `Transport\PdoDeduplicationStore` | `Adapter\PostgreSql\PostgreSqlDeduplicationStore` |
| `DeadLetter\PdoDeadLetterStore` | `Adapter\PostgreSql\PostgreSqlDeadLetterStore` |
| `Transport\DeduplicationStore` | `Consumer\DeduplicationStore` |
| `Transport\IdleSleep` | `Outbox\IdleSleep` |
| `Transport\Amqp\AmqpConsumer` | `Adapter\Amqp\AmqpConsumer` |
| `Transport\Amqp\AmqpMessagePublisher` | `Adapter\Amqp\AmqpMessagePublisher` |
| `Transport\Amqp\AmqpPublishConfig` | `Adapter\Amqp\AmqpPublishConfig` |
| `Transport\Amqp\AmqpQueueConfig` | `Adapter\Amqp\AmqpQueueConfig` |
| `Transport\Amqp\AmqpRelay` (removed) | `Outbox\Relay` with `Adapter\Amqp\AmqpMessagePublisher` |
| `Transport\Amqp\AmqpRetryPolicy` | `Retry\ExponentialRetryPolicy` |
| `Storage\Platform` (removed) | none; each PostgreSQL class holds its own SQL |
| `Storage\PostgreSqlPlatform` (removed) | none; `Adapter\PostgreSql\PostgreSqlSchemaInstaller` holds the DDL |

All other public classes of 0.6.0 keep their name and namespace. This includes `Message`, `ErrorHandler`,
`Console\*`, `Consumer\CallableDispatcher`, `Consumer\IncomingMessage`, `Consumer\MessageDispatcher`,
`DeadLetter\DeadLetter`, `DeadLetter\DeadLetterStore`, `DeadLetter\ReplayService`, `Outbox\OutboxStore`,
`Outbox\OutboxProducer`, `Outbox\OutboxRecord`, `Outbox\ClaimOutcome`, `Retry\Backoff`, `Retry\RetryAction`,
`Retry\RetryDecision`, `Serializer\*`, `Time\EpochMillis` and `Observability\BrokerEvents`.

New classes of this release (they are not in 0.6.0), relative to `Freyr\MessageBroker\`:

| New class | Package |
|---|---|
| `Consumer\DeliveryProcessor`, `Consumer\Delivery`, `Consumer\DeliveryControl` | core |
| `Outbox\Relay`, `Outbox\OutboxPublisher`, `Outbox\OutboxRetention` | core |
| `Retry\RetryPolicy`, `Retry\ExponentialRetryPolicy` | core |
| `Storage\SchemaInstaller`, `Storage\Transaction` | core |
| `Adapter\PostgreSql\PostgreSqlSchemaInstaller`, `Adapter\PostgreSql\PdoTransaction` | PostgreSQL adapter |
| `Adapter\Amqp\AmqpDeliveryControl`, `Adapter\Amqp\WaitQueuePublisher` | AMQP adapter |

### Wiring: producer and outbox store

Before:

```php
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;

$outbox = new PdoOutboxStore($pdo, new PostgreSqlPlatform());
$producer = new OutboxProducer($outbox, new JsonWireFormat(), lane: 'orders');
```

After:

```php
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;

$outbox = new PostgreSqlOutboxStore($pdo);
$producer = new OutboxProducer($outbox, new JsonWireFormat(), lane: 'orders');
```

### Wiring: relay

Before:

```php
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;

$relay = new AmqpRelay($outbox, $channel, new AmqpPublishConfig(exchange: 'orders'), JsonWireFormat::CONTENT_TYPE, lane: 'orders', confirmTimeoutSec: 5);
$relay->run();
```

After:

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Adapter\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Outbox\Relay;

$relay = new Relay(
    $outbox,
    new AmqpMessagePublisher($channel, new AmqpPublishConfig(exchange: 'orders'), JsonWireFormat::CONTENT_TYPE, confirmTimeoutSec: 5),
    lane: 'orders',
);
$relay->run();
```

The other arguments (`lane`, `batchSize`, `backoff`, `errorHandler`, `idleSleepMs`, `logger`, `events`) keep their names and defaults.

### Wiring: consumer

Before:

```php
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;

$consumer = new AmqpConsumer(
    channel: $channel,
    queue: new AmqpQueueConfig('orders_q'),
    deserializer: new JsonDeserializer(),
    dispatcher: $dispatcher,
    pdo: $pdo,
    deduplication: new PdoDeduplicationStore($pdo, new PostgreSqlPlatform()),
    retryPolicy: new AmqpRetryPolicy(maxAttempts: 5, backoff: $backoff),
    deadLetters: new PdoDeadLetterStore($pdo, new PostgreSqlPlatform()),
    name: 'orders_consumer',
);
```

After:

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Adapter\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;

$consumer = new AmqpConsumer(
    channel: $channel,
    queue: new AmqpQueueConfig('orders_q'),
    processor: new DeliveryProcessor(
        deserializer: new JsonDeserializer(),
        dispatcher: $dispatcher,
        transaction: new PdoTransaction($pdo),
        deduplication: new PostgreSqlDeduplicationStore($pdo),
        retryPolicy: new ExponentialRetryPolicy(maxAttempts: 5, backoff: $backoff),
        deadLetters: new PostgreSqlDeadLetterStore($pdo),
        name: 'orders_consumer',
    ),
);
```

Use the same PDO connection for the transaction, the deduplication store and your handler.

### Wiring: schema setup and maintain commands

Before:

```php
use Freyr\MessageBroker\Console\OutboxMaintainCommand;
use Freyr\MessageBroker\Console\SetupSchemaCommand;
use Freyr\MessageBroker\Outbox\OutboxPartitionManager;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;

$application->add(new SetupSchemaCommand($pdo, new PostgreSqlPlatform()));
$application->add(new OutboxMaintainCommand(new OutboxPartitionManager($pdo)));
```

After:

```php
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlSchemaInstaller;
use Freyr\MessageBroker\Console\OutboxMaintainCommand;
use Freyr\MessageBroker\Console\SetupSchemaCommand;

$application->add(new SetupSchemaCommand(new PostgreSqlSchemaInstaller($pdo)));
$application->add(new OutboxMaintainCommand(new PostgreSqlOutboxRetention($pdo)));
```

The options, the output and the exit codes of both commands do not change.

### Changed constructor and method signatures

- `PostgreSqlOutboxStore`, `PostgreSqlDeduplicationStore` and `PostgreSqlDeadLetterStore` take no `Platform` argument:
  `new PostgreSqlOutboxStore($pdo)`, `new PostgreSqlDeduplicationStore($pdo, deleteChunkSize: ...)`,
  `new PostgreSqlDeadLetterStore($pdo)`.
- `PostgreSqlOutboxRetention::ensurePartitions($daysAhead = 3, $today = null)` is now
  `ensureCapacity($daysAhead, $today = null)`. `$daysAhead` has no default.
- `PostgreSqlOutboxRetention::purge()` takes `(retentionDays, dryRun, dropUnpublished, today)`.
  `$dropUnpublished` is now before `$today`. `$today` is a test parameter of the PostgreSQL class
  and is not part of the `OutboxRetention` port.
- `SetupSchemaCommand` takes a `SchemaInstaller` (was `PDO` and `Platform`).
- `OutboxMaintainCommand` takes an `OutboxRetention` (was the concrete partition manager).
- `Outbox\Relay` replaces `AmqpRelay`. It takes an `OutboxPublisher` in place of the channel, the publish
  config and the content type.
- `AmqpConsumer` takes `(AMQPChannel $channel, AmqpQueueConfig $queue, DeliveryProcessor $processor, int $confirmTimeoutSec = 5)`.
  Before, it took 13 parameters. Pass `deserializer`, `dispatcher`, `deduplication`, `retryPolicy`, `deadLetters`,
  `name`, `errorHandler`, `logger` and `events` to `DeliveryProcessor`. Give it `new PdoTransaction($pdo)` as
  `transaction` in place of the `pdo` parameter.
- `Retry\RetryPolicy::decide(int $attempt, Throwable $error, IncomingMessage $message)` takes the message as a
  third parameter. `ExponentialRetryPolicy` has the same constructor as the old `AmqpRetryPolicy`.
- The retry republish to the TTL wait queue moves from `AmqpConsumer` to `Adapter\Amqp\WaitQueuePublisher`.
  The behavior does not change.
