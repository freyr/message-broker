# Upgrade guide

## From 0.14.x to 0.15.0

The adapters are separate Composer packages. `freyr/message-broker` 0.15.0 is
the core only: the ports, the relay, the delivery processor, the console
commands, the webhook delivery queue and the test kit. It holds no adapter
code and replaces no package. Each adapter has its own version (the first is
0.1.0) and requires the core `^0.15`. The release changes no schema, no table,
no message header and no class name; only the namespaces of the adapters and
the package names change. There is no alias for the old namespaces.

### Install

Require the core and each adapter that you use:

```bash
composer require freyr/message-broker:^0.15 \
    freyr/message-broker-storage-postgresql \
    freyr/message-broker-transport-amqp
```

Add `freyr/message-broker-webhook-http` for the HTTP webhook sender and
`freyr/message-broker-ratelimit-redis` for the Redis rate limit store. The
dependencies of an adapter (`php-amqplib/php-amqplib`, `symfony/http-client`,
`symfony/http-foundation`, `ext-pdo_pgsql`) come with its package. The Redis
package still needs a client of your choice: `ext-redis` or `predis/predis`.

### Packages and namespaces

| Old package (0.14.x) | New package | Old namespace | New namespace |
|---|---|---|---|
| `freyr/message-broker` | `freyr/message-broker` (the core) | `Freyr\MessageBroker\` | `Freyr\MessageBroker\` (no change) |
| `freyr/message-broker-postgresql` (replaced by `freyr/message-broker`) | `freyr/message-broker-storage-postgresql` | `Freyr\MessageBroker\Adapter\PostgreSql\` | `Freyr\MessageBroker\Storage\PostgreSql\` |
| `freyr/message-broker-amqp` (replaced by `freyr/message-broker`) | `freyr/message-broker-transport-amqp` | `Freyr\MessageBroker\Adapter\Amqp\` | `Freyr\MessageBroker\Transport\Amqp\` |
| `freyr/message-broker-http` (replaced by `freyr/message-broker`) | `freyr/message-broker-webhook-http` | `Freyr\MessageBroker\Adapter\Http\` | `Freyr\MessageBroker\Webhook\Http\` |
| `freyr/message-broker-redis` (replaced by `freyr/message-broker`) | `freyr/message-broker-ratelimit-redis` | `Freyr\MessageBroker\Adapter\Redis\` | `Freyr\MessageBroker\RateLimit\Redis\` |

The test set-up classes move with their package:
`Freyr\MessageBroker\Adapter\PostgreSql\Testing\PostgreSqlTestSupport` is
`Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport`, and
`Freyr\MessageBroker\Adapter\Redis\Testing\RedisTestSupport` is
`Freyr\MessageBroker\RateLimit\Redis\Testing\RedisTestSupport`.

- **BREAKING:** change each `use Freyr\MessageBroker\Adapter\<Technology>\…`
  to the new namespace of the table. A search and replace of the four
  namespace prefixes is enough; the class names do not change.
- **BREAKING:** a `composer require` of `freyr/message-broker` alone gives no
  adapter now. Require the adapters by their new names.
- The virtual packages `freyr/message-broker-storage-implementation`,
  `freyr/message-broker-transport-implementation` and
  `freyr/message-broker-webhook-sender-implementation` stay; the Redis package
  provides the new `freyr/message-broker-ratelimit-implementation`.
- A later minor release of the core (0.16.0) is breaking for the adapters
  too: each adapter then gets a release with the new core constraint.

## From 0.13.x to 0.14.0

The webhook delivery loop calls the flow control ports: a rate limit for each
lane and a global rate limit, and a circuit breaker for each lane. With the
defaults (`NoRateLimit`, `NoCircuitBreaker`) the loop claims and sends as
before. The release changes no schema. All packages move to `^0.14` together.

- **BREAKING (only for a `DeliveryStore` of your own):**
  `Webhook\DeliveryStore::claim()` takes a map from each lane to its row
  limit, in priority order:
  `claim(array $limitForEachLane, int $total, int $leaseMs)` in place of
  `claim(array $lanes, int $limitForEachLane, int $total, int $leaseMs)`. The
  order of the keys is the priority when `$total` cuts the claim. PHP turns a
  numeric lane ID into an `int` key, so read each key as a string. A call
  `claim($lanes, $limit, $total, $leaseMs)` becomes
  `claim(array_fill_keys($lanes, $limit), $total, $leaseMs)`.
- **BREAKING (only for a `DeliveryStore` of your own):** implement the new
  method `dueCounts(array $lanes, int $cap): array`: the due, unfinished rows
  of each given lane, at most `$cap`, also 0, in the order of `$lanes`, with
  no change and no lock. `DeliveryStoreContractTestCase` states both
  contracts. `PostgreSqlDeliveryStore` and `InMemoryDeliveryStore` implement
  them.
- **BREAKING (only for tests that read the test kit):**
  `Testing\InMemoryDeliveryStore::$claims` records `limitForEachLane` as a
  map (lane => row limit) in place of the keys `lanes` (a list) and
  `limitForEachLane` (an int); each entry is now
  `['limitForEachLane' => [...], 'total' => ..., 'leaseMs' => ...]`. The new
  `$dueCountCalls` records the arguments of each `dueCounts()` call
  (`lanes`, `cap`).
- `DeliveryLoopConfig` has three new optional values: `permitWaitMs` (default
  50), `globalRate` and `defaultLaneRate` (`?Rate`, default `null`). A null
  global rate means no global bucket. When `globalRate` is set, `run()`
  refuses a batch size larger than its burst capacity. A config with none of
  them works as before.
- `DeliveryLoop` has two new optional constructor parameters, after
  `events`: `rateLimiter` (default `NoRateLimit`) and `circuitBreaker`
  (default `NoCircuitBreaker`). The loop reads the slow limit from
  `CircuitBreaker::policy()`.
- `Webhook\Subscription` has the new optional fourth parameter
  `?Rate $rateLimit = null`: the rate of the lane of the subscription. The
  rate of a lane is the subscription value, then `defaultLaneRate`, then no
  lane limit.
- With a rate limiter, the loop reads `dueCounts()` each pass (one read
  statement) and calls `find()` for each lane at the lane refresh interval.
- New events: `webhook.lane_opened`, `webhook.lane_blocked`,
  `webhook.lane_returned`, `webhook.permit_wait` and `webhook.lane_at_limit`
  (see `docs/WEBHOOKS.md`, "Events").

## From 0.12.x to 0.13.0

A webhook delivery has no time limit now: a row waits until it is delivered,
reaches the retry limit, or gets a permanent refusal. An operator ends old
rows with the new command `message-broker:webhook:abandon`. The change is
breaking for an application that uses the webhook queue. It changes no schema
and no other part of the library.

- **BREAKING:** remove the parameters `maxAgeMs`, `expiredCheckIntervalMs` and
  `retentionDays` from each `new DeliveryLoopConfig(...)`. The loop has no
  maximum age, no check of expired rows and no start check against the
  retention period. The start check of the lease against
  `DeliverySender::maxSendMs()` stays.
- **BREAKING:** `Webhook\DeliveryStore::claimExpired()` is removed, and the
  port has two new methods: `abandon(int $before, ?array $lanes, int $limit):
  int` and `countUnfinished(int $before, ?array $lanes): array`. A store of
  your own must implement them; `DeliveryStoreContractTestCase` states their
  contract. `PostgreSqlDeliveryStore` and `InMemoryDeliveryStore` implement
  them.
- **BREAKING:** `Webhook\DeliveryRetryPolicy::longestDelayMs()` is removed.
  Remove it from a retry policy of your own.
  `DefaultDeliveryRetryPolicy::longestDelayMs()` is removed too.
- **BREAKING:** the event `BrokerEvents::WEBHOOK_EXPIRED` (`webhook.expired`)
  and the constant `DeliveryLoop::REASON_EXPIRED` are removed. A listener for
  `webhook.expired` receives no event: listen to the new event
  `BrokerEvents::WEBHOOK_ABANDONED` (`webhook.abandoned`, context `lane` and
  `count`) instead. The dead letters of the loop have no error class
  `expired` any more; an abandoned row has the error class `abandoned`.
- Add `Console\WebhookAbandonCommand` to your console application:
  `new WebhookAbandonCommand(new PostgreSqlDeliveryStore($pdo), $events,
  $logger)`. Run `message-broker:webhook:abandon --before=<date>
  [--lane=<lane>]... [--dry-run]` by hand for old rows that no rule ends: the
  rows of a lane whose endpoint is blocked, and the rows of a subscription
  that you removed. The abandoned rows go to the dead letters, so
  `dlq:replay` can send them again (see `docs/CLI_REFERENCE.md`).
- `--retention-days` of `webhook:maintain` is no longer tied to a value of the
  loop. A partition that holds an unfinished row stays until the row ends;
  the command reports it and exits with code 1, as before. Keep the alert on
  that exit code, and end the old rows with `webhook:abandon`.
- An unfinished row that the earlier versions would expire now stays and is
  delivered when its endpoint accepts it. Before the upgrade, decide for the
  old rows of a removed subscription: abandon them after the upgrade with
  `webhook:abandon --before=<date>`.

## From 0.12.2 to 0.12.3

This release adds the webhook delivery queue (see `docs/WEBHOOKS.md`). It
is additive: an application that does not use webhooks needs no change and
runs no new DDL.

- To use webhooks, create the table with `message-broker:webhook:setup` (or
  `PostgreSqlDeliverySchemaInstaller::install()`), schedule
  `message-broker:webhook:maintain` once a day, and run workers with
  `message-broker:webhook:run`. The library registers no command: add
  `WebhookSetupCommand`, `WebhookMaintainCommand` and `WebhookRunCommand` to
  your console application (see `docs/CLI_REFERENCE.md`).
- Give `--retention-days` of `webhook:maintain` the value of `retentionDays` of
  the `DeliveryLoopConfig` (default `7` for both). Since 0.13.0 this value of
  the loop is removed (see above).
- To replay webhook dead letters (source `webhook:<lane>`), pass the delivery
  store to `ReplayService` as its new last argument, on the connection of the
  transaction: `new ReplayService($deadLetters, $outbox, $transaction,
  $wireFormat, $events, deliveries: new PostgreSqlDeliveryStore($pdo))`. With
  no delivery store, `dlq:replay` refuses a webhook dead letter as not
  replayable (exit code 1). Each other dead letter replays to the outbox as
  before.
- `dlq:replay --all` with no `--source` also replays the webhook dead letters
  that match, each to the delivery table of its lane. Use
  `--source=<queue>` to replay the rows of one consumer queue only.
- `ReplayService::replay()` and `check()` return a `ReplayDestination` (the
  lane that the replay uses, and whether it is a webhook lane) instead of
  `void`. A caller that ignores the return value needs no change.

## From 0.11.x to 0.12.0

This release adds worker limits to `message-broker:consume` and changes the
signature of `AmqpConsumer::run()`. It changes no schema.

- **BREAKING:** `AmqpConsumer::run()` takes a `WorkerLimits` instead of
  `messageLimit`: `run(?WorkerLimits $limits = null, ?int $idleTimeoutSec =
  null)`. Replace `run(messageLimit: 5)` with
  `run(new WorkerLimits(maxMessages: 5))`, and `run(5, 10)` with
  `run(new WorkerLimits(maxMessages: 5), 10)`. `run()` with no argument and
  `run(idleTimeoutSec: 10)` do not change.
- To use the new command options (`--limit`, `--time-limit`, `--memory-limit`,
  `--failure-limit`), change each registered callable to
  `static fn (WorkerLimits $limits) => $consumer->run($limits)` (and the same
  for relays: `static fn (WorkerLimits $limits) => $relay->run($limits)`). A
  callable with no parameter still runs, with no limit; the command prints a
  warning when a limit option is set.
- A limit stop exits with code `0`. The container must start again after each
  exit, exit code `0` included: a Kubernetes `Deployment` does this
  (`restartPolicy: Always`; see `docs/PRODUCTION.md`, "Worker limits").
- `DeliveryProcessor::process()` returns a `DeliveryOutcome` instead of `void`.
  A caller that ignores the return value needs no change.

## From 0.11.0 to 0.11.1

- `DeliveryProcessor` takes a `DeadLetterSink` as `deadLetters` (the table or
  a queue of the transport); a `DeadLetterStore` still fits, so no change is
  necessary.
- Upgrade the process that runs `message-broker:dlq:import` before a consumer
  uses `AmqpDeadLetterSink`: an older import ignores the header
  `x-dead-letter`. For a rollback, see `transport/amqp/docs/DEAD_LETTERS.md`, "Upgrade
  and rollback order".

## From 0.10.x to 0.11.0

This release makes the replay of a dead letter atomic, refuses replayed and
not replayable rows, adds `--limit` and `--rate` to the bulk replay, and sends
each replay to one consumer. It changes no schema.

**Upgrade order.** Deploy the new release to all consumers first, and replay
with the new release only after that. A consumer of an older release does not
read the header `x-replay-target`, so it processes each replay.

- **BREAKING:** `ReplayService::__construct()` takes `Transaction $transaction`
  as the third argument: `(DeadLetterStore $deadLetters, OutboxStore $outbox,
  Transaction $transaction, WireFormat $wireFormat, ?BrokerEvents $events =
  null)`. Pass a transaction on the connection of the two stores, for example
  `new PdoTransaction($pdo)` with the `$pdo` of `PostgreSqlDeadLetterStore` and
  `PostgreSqlOutboxStore`. When two connections cannot be avoided, the
  transaction must wrap the connection of the dead letter store (see
  `docs/DLQ_OPERATIONS.md`).
- **BREAKING:** `DeadLetterStore::markReplayed(string $id): bool`. A custom
  implementation sets the mark only when `replayed_at` is null, returns `true`
  only when it set the mark, and returns `false` with no error for an unknown
  ID, an ID that is not valid and a replayed row. Run
  `DeadLetterStoreContractTestCase`.
- **BREAKING (behavior):** `dlq:replay <id>` of a replayed row fails with exit
  code 1 and inserts nothing. A message that failed again after its replay has
  a new open row; replay that row.
- **BREAKING (behavior):** `dlq:replay --all` reads only rows with the mark
  `replayable = true` unless the operator gives `--not-replayable`, and only
  rows that failed before the start of the run. It skips a row that it cannot
  replay and continues; it exits with code 1 when a row failed with an
  unexpected error. The last line of its output gives four counts.
  `--name`, `--source` and `--since` keep their names in the shared filter
  options.
- **BREAKING (behavior):** a consumer acks with no dispatch a delivery whose
  `x-replay-target` names a different queue. Upgrade all consumers before the
  first replay.
- `ReplayService` throws `DeadLetterNotFound`, `AlreadyReplayed` and
  `NotReplayable`; each extends `RuntimeException`, so a catch of
  `RuntimeException` still works. `ReplayService::check()` is new.
- The event `message.replayed` has the new field `target`; the event
  `message.replay_skipped` (`BrokerEvents::REPLAY_SKIPPED`) is new.

Before a rollback to 0.10.x, wait until no unpublished replay is left in the
outbox, or accept that each consumer processes those rows:

```sql
SELECT COUNT(*) FROM outbox_messages
WHERE published_at IS NULL AND headers ? 'x-replay-target';
```

## From 0.9.x to 0.10.0

This release keeps one open dead letter row for each message and work queue,
adds the `replayable` mark, and gives the `DeadLetterStore` port one filter
object for each read and delete. It changes the schema of `dead_letters`, the
behavior of `store()` and the port signatures. Migrate the table first, then
deploy the code.

### Migration of `dead_letters`

The new code needs the column `dead_letters.replayable` and the partial unique
index `uq_dead_letters_open`. The `store()` of the new code names the index in
its `ON CONFLICT` clause: with no index, each dead letter write of the new code
fails with `there is no unique or exclusion constraint matching the ON CONFLICT
specification`. Run steps 1 to 4 with the old code in service, in this order,
and deploy the new code directly after step 4:

1. Add the column. It has a default, and the old code does not read it:

   ```sql
   ALTER TABLE dead_letters ADD COLUMN replayable BOOLEAN NOT NULL DEFAULT TRUE;
   ```

2. Give each raw dead letter with the literal message ID `unknown` its own
   message ID. With no step 2, step 3 deletes all of them except the newest:

   ```sql
   UPDATE dead_letters SET message_id = 'unknown-' || id WHERE message_id = 'unknown';
   ```

3. Count the duplicate open rows, then delete them; the newest row of each
   pair stays:

   ```sql
   SELECT message_id, source, COUNT(*) FROM dead_letters
   WHERE replayed_at IS NULL
   GROUP BY message_id, source
   HAVING COUNT(*) > 1;

   DELETE FROM dead_letters d
   USING dead_letters n
   WHERE d.replayed_at IS NULL AND n.replayed_at IS NULL
     AND d.message_id = n.message_id AND d.source = n.source
     AND (n.failed_at, n.id) > (d.failed_at, d.id);
   ```

4. Build the index. It blocks no write. It cannot run inside a transaction
   block, so run it alone:

   ```sql
   CREATE UNIQUE INDEX CONCURRENTLY uq_dead_letters_open ON dead_letters (message_id, source) WHERE replayed_at IS NULL;
   ```

   When it fails with a duplicate key, it leaves an invalid index. Drop it,
   then repeat steps 2, 3 and 4:

   ```sql
   DROP INDEX CONCURRENTLY IF EXISTS uq_dead_letters_open;
   ```

   The repeat starts at step 2, because the old code still writes the literal
   `unknown` after the first step 2. A repeat of only step 3 deletes those rows
   as duplicates, and their deliveries are already acked.
5. Deploy the new code.

Steps 1 to 3 can run in one transaction. A new installation gets the column
and the index from `message-broker:setup:schema`. The installer does not alter
a table that exists, so `setup:schema` on a table of 0.9.x does not add the
column: run the steps above.

Why the order is fixed:

- A failed dead letter write leaves the delivery unacked. The delivery
  returns, and its delivery count goes up by 1. When the write fails at each
  delivery, the delivery limit of the broker applies (20 by default on a
  RabbitMQ quorum queue): a queue with a dead letter exchange moves the message
  there, and a queue with none drops it. A failed write is thus not free of
  loss.
- The new code must not start before the index exists: each dead letter write
  fails, also the write of the delivery limit of the consumer, and each failure
  of each message ends at the broker limit.
- Steps 1 to 3 do not change the behavior of the old code.
- From step 4 to step 5 the old code writes a plain `INSERT`. Two cases fail
  with a unique violation: a message that fails again while it has an open
  row, and a second raw dead letter with no ID on one work queue (the old code
  writes the literal `unknown`). Each such delivery returns with a higher
  count. Keep this window short: deploy the new code directly after step 4,
  and check that each work queue has a dead letter exchange during the window.
- The other orders cost more. A deploy before step 4 makes every dead letter
  write fail until the index exists. An index build before step 3 fails on the
  duplicates.

To roll back to 0.9.x, drop the index before the old code runs, because the
plain `INSERT` of the old code fails on a second open row of one pair:

```sql
DROP INDEX CONCURRENTLY uq_dead_letters_open;
```

The column can stay: the old code does not write it, and its default is
`TRUE`. The rows that the upsert merged and the duplicates that step 3 deleted
do not come back.

### New signatures of `DeadLetterStore`

`list()`, `count()` and `purge()` take a `DeadLetter\DeadLetterFilter` in place
of the separate filter parameters (see `docs/PORTS.md`):

| Old call | New call |
| --- | --- |
| `list($name, $source, $sinceMs, $limit, $offset, $replayed)` | `list(new DeadLetterFilter(messageName: $name, source: $source, failedFromMs: $sinceMs, replayed: $replayed), $limit, $offset)` |
| `count($name, $source, $sinceMs, $replayed)` | `count(new DeadLetterFilter(...))` |
| `purge($name, $source, $olderThanMs)` | `purge(new DeadLetterFilter(messageName: $name, source: $source, failedUntilMs: $olderThanMs))` |
| — | `ids(DeadLetterFilter $filter, int $limit)` and `stats(DeadLetterFilter $filter)`: an own implementation of `DeadLetterStore` must add them. |
| `store()` inserts a new row for each call | `store()` changes nothing when a row with the same `id` exists; otherwise it updates the open row of the same message ID and source, or inserts a new row. An own implementation must do the same and pass the contract test case. |

A call with no argument, `list()` or `count()`, becomes
`list(new DeadLetterFilter())` or `count(new DeadLetterFilter())`. An empty
filter matches every row, so `purge(new DeadLetterFilter())` deletes every row,
as `purge()` did.

`DeadLetter` has the new last constructor parameter `bool $replayable = true`,
and `DeadLetter::fromFailure()` the same. A caller with positional arguments
still runs and gets `true`.

`InMemoryDeadLetterStore::callsOf()` records the filter object as `filter`, in
place of the separate parameters. `add()` stores the object as it is, with no
upsert.

### Commands

- `dlq:list`, `dlq:purge`, `dlq:replay --all` and the new `dlq:stats` take the
  same filter options: `--name`, `--source`, `--error`, `--message-id`,
  `--since`, `--until`, `--min-attempts`, `--replayed` / `--not-replayed` and
  `--replayable` / `--not-replayable`. `--since` keeps its meaning for a
  duration and also takes an ISO 8601 date-time with an offset.
- `dlq:purge` counts with the full filter. Its texts change to
  `[dry-run] would delete N dead letters.` and `Delete N dead letters? [y/N]`.
  `--older-than` together with `--until` ends with exit code 2.
- `dlq:replay --all` always adds the condition `--not-replayed`; `--replayed`
  together with `--all` ends with exit code 2.
- `dlq:list` has the new column `replayable`, and `dlq:show` the new line
  `replayable:`.
- Register the new command `new DlqStatsCommand(DeadLetterStore $store)`.

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
methods (see `docs/PORTS.md`):

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
  [transport/amqp/docs/CONSUMER.md](transport/amqp/docs/CONSUMER.md), *The return rule*.
- A confirm timeout of the wait-queue publish ends `run()` with the exception,
  so `message-broker:consume` exits with a failure. In the earlier release it
  ended `run()` as an idle stop, with exit code 0.
- `AmqpConsumer` takes an optional `logger` (the new last constructor
  parameter, a PSR-3 `LoggerInterface`). It receives a failure inside the
  return.

### Retry policy

`ExponentialRetryPolicy` takes the optional `deadLetterOn` and `discardOn`
lists of throwable classes (empty by default, so nothing changes when you do
not pass them). See [docs/CONSUMER.md](docs/CONSUMER.md), *Error
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
