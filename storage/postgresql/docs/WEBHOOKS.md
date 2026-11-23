# Webhook delivery storage

This document describes the PostgreSQL part of the webhook queue: the table
`webhook_deliveries`, its index, the delivery store, the retention, the
pg_partman configuration, the alert query, and the flow control tables with
the rate limiter and the circuit breaker. The model of the queue, the
ports and the delivery loop are in [docs/WEBHOOKS.md](https://github.com/freyr/message-broker/blob/main/docs/WEBHOOKS.md).
The other tables are in [STORAGE.md](STORAGE.md). The outbox retention, which
has the same rules, is in [RETENTION.md](RETENTION.md).

| Class | Implements | Constructor |
|---|---|---|
| `PostgreSqlDeliveryStore` | `Webhook\DeliveryStore` | `PDO $pdo` |
| `PostgreSqlDeliverySchemaInstaller` | `Storage\SchemaInstaller` | `PDO $pdo` |
| `PostgreSqlDeliveryRetention` | `Webhook\DeliveryRetention` | `PDO $pdo` |
| `PostgreSqlRateLimiter` | `Webhook\RateLimiter` | `PDO $pdo` |
| `PostgreSqlCircuitBreaker` | `Webhook\CircuitBreaker`, `Webhook\LaneStates` | `PDO $pdo, CircuitBreakerPolicy $policy = new CircuitBreakerPolicy()` |

## The table

`webhook_deliveries` holds one row for each message and subscription. The
lane of a row is the ID of its subscription. The table holds no URL and no
secret: the delivery loop reads the subscription of a lane at send time.

| Column | Type | Purpose |
|---|---|---|
| `id` | `UUID` | The ID of the row (UUIDv7). |
| `message_id` | `UUID` | The ID of the message. All rows of one message have the same value. The receiver gets it. |
| `lane` | `VARCHAR(64)` | The subscription ID. |
| `message_name` | `VARCHAR(255)` | The name of the message. |
| `body` | `BYTEA` | The final bytes of the request body. |
| `headers` | `JSONB` | The produce-time headers. |
| `created_at` | `TIMESTAMP(3)` | The partition key, and the date that `webhook:abandon` compares. |
| `available_at` | `TIMESTAMP(3)` | The earliest time of the next claim. A claim sets it to the end of the lease. |
| `attempts` | `INTEGER` | The number of requests that got a result. |
| `completed_at` | `TIMESTAMP(3) NULL` | The time of the final state. `NULL` means unfinished. |
| `outcome` | `SMALLINT NULL` | The final state: `1` delivered, `2` dead. `NULL` while the row is unfinished. |

All timestamps are UTC. The primary key is `(id, created_at)`, because the
primary key of a partitioned table must include the partition key.

The table is partitioned by range on `created_at`, with one partition for each
UTC day (`webhook_deliveries_pYYYYMMDD`) and no default partition, as
`outbox_messages`. An insert for a day with no partition fails, and the
transaction of the application fails with it, so create the partitions ahead
of time.

**The index.** The partial index `idx_webhook_deliveries_due` on
`(lane, available_at) WHERE completed_at IS NULL` serves the claim. A finished
row leaves the index, so the index holds only the unfinished rows and stays
small. The alert query reads it too.

## Schema setup

`PostgreSqlDeliverySchemaInstaller` creates the table, the index and the daily
partitions, and the flow control tables `webhook_rate_limit` and
`webhook_lanes` ([Flow control tables](#flow-control-tables)). It is apart
from `PostgreSqlSchemaInstaller`, so an application that does not use webhooks
runs no DDL for it. `install(int $daysAhead)`
creates what is missing, with the partitions from today to today plus
`$daysAhead`, and is safe to repeat. `sql(int $daysAhead)` returns the DDL of
a new database; its partition DDL is not idempotent (`ATTACH`). The dead
letters of the deliveries go to `dead_letters`, which
`PostgreSqlSchemaInstaller` creates.

The console commands `message-broker:webhook:setup` and
`message-broker:webhook:maintain` take these classes (see
[docs/CLI_REFERENCE.md](https://github.com/freyr/message-broker/blob/main/docs/CLI_REFERENCE.md)). Without the commands,
call `install()` from your deployment, and `PostgreSqlDeliveryRetention` from a
daily job (below).

```php
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliverySchemaInstaller;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryStore;

(new PostgreSqlDeliverySchemaInstaller($pdo))->install(3);

$producerStore = new PostgreSqlDeliveryStore($pdo);      // the connection of the application
$workerStore = new PostgreSqlDeliveryStore($workerPdo);  // a dedicated connection for each worker
$retention = new PostgreSqlDeliveryRetention($maintenancePdo);
```

## The delivery store

`PostgreSqlDeliveryStore` keeps the contract of the port (it passes
`Testing\DeliveryStoreContractTestCase`). The PostgreSQL details:

- **Insert.** `insert()` writes on the connection that it gets and opens no
  transaction, so the rows commit or roll back with the transaction of the
  application. A record with no `availableAt` gets `available_at =
  created_at`: the row is due at once.
- **Time.** The present time of the store is the database time: `now()` in
  UTC, cast one time to `TIMESTAMP(3)`. The same value is the due limit
  (`available_at <= now`) and the start of the lease. Thus a lease of 0 ms is
  due again at the next claim.
- **Claim.** `claim()` is one statement. The lanes and their row limits come
  as one JSON array of `[lane, row limit]` pairs, with their position
  (`jsonb_array_elements ... WITH ORDINALITY`). For each lane, a `LATERAL`
  subquery reads `idx_webhook_deliveries_due` from the lane start, takes at
  most the row limit of that lane with `FOR UPDATE SKIP LOCKED`, and the total
  limit then cuts the rows in the order of the lane positions. The `UPDATE`
  sets `available_at` to the lease. In autocommit mode the statement is its
  own transaction, so the lease commits before the method returns, and
  concurrent workers skip the rows of each other and do not wait. The
  statement text is the same for each number of lanes, so pgbouncer and
  pdo_pgsql keep one prepared statement. `->>` gives a numeric lane ID as its
  text.
- **Due counts.** `dueCounts()` is one read statement with no lock and no
  write. For each lane of a JSON array, in the order of the array, a
  subquery counts the due, unfinished rows on `idx_webhook_deliveries_due` (one
  index start for each lane), with `LIMIT` of the cap, so a lane with a large
  backlog reads at most the cap of index entries. The due limit is the
  database time of the claim. A row that a claim of a different session locks
  still counts until that claim commits its lease.
- **Results.** `complete()` writes all outcomes in one `UPDATE` with the lease
  check (`completed_at IS NULL AND available_at = lease`), and then inserts
  the dead letters of the dead outcomes that passed the check, through
  `PostgreSqlDeadLetterStore::store()`. One transaction holds both: when an
  insert fails, the final states roll back too and the error propagates. The
  dead letter follows the open row rule of `uq_dead_letters_open`: one open
  row for each pair of message ID and source (`webhook:<lane>`). Thus one
  message that dies in two lanes has two open dead letters, and a failure
  after a replay inserts a new row. When one call holds two outcomes for one
  row, the first outcome applies.
- **Abandon.** `abandon()` is one `UPDATE` in a transaction of the store. A
  subquery picks at most the limit of unfinished rows with `created_at`
  earlier than the date, of the lanes of a JSON array or of all lanes, with
  `FOR UPDATE SKIP LOCKED`. The `UPDATE` sets the final state dead and keeps
  `available_at` and `attempts`; then the dead letters (error class
  `abandoned`) go in through `PostgreSqlDeadLetterStore::store()`, in the same
  transaction. A lease does not protect a row, so a leased row is abandoned
  too, and the later `complete()` of its worker fails the lease check because
  the row is finished. A row that a different session locks (a claim or a
  result write in progress) is skipped. The condition on `created_at` lets
  PostgreSQL skip the partitions of later days.
- **Count.** `countUnfinished()` counts the same rows for each lane, with no
  lock and no change, in the byte order of the lane names (`COLLATE "C"`).
- **Pooler.** The store holds no session state and no session lock, so it
  works behind pgbouncer in transaction mode (see
  [CONNECTION_POOLING.md](CONNECTION_POOLING.md)).

The plan of the claim over 20 lanes, with 10,000 due rows in one lane, is a
Nested Loop over the lane list, with an Index Scan on the partial index of
each partition and LockRows in each loop. The `UPDATE` finds the claimed rows
through the primary key index of each partition. The plan of the due counts
has the same index scans with no LockRows and no write. Functional tests check
that both plans read the partial index of each partition and have no
sequential scan.

## Retention

`PostgreSqlDeliveryRetention` implements `Webhook\DeliveryRetention` with the
rules of the outbox retention:

- `ensureCapacity(int $daysAhead)` creates the missing daily partitions from
  today to today plus `$daysAhead` (`CREATE TABLE`, then `ATTACH PARTITION`).
- `purge(int $retentionDays, bool $dryRun = false)` drops each daily partition
  that lies wholly before the cutoff (today midnight UTC minus
  `$retentionDays`): `DETACH PARTITION ... CONCURRENTLY`, then `DROP TABLE`.
  It must run outside a transaction.
- An expired partition that holds an unfinished row (`completed_at IS NULL`)
  is kept and reported in `RetentionReport::$kept` (partition name and count
  of unfinished rows). There is no option to drop it. A later run drops it
  when its rows are final. An abandoned row is final.
- The rows are counted again on the detached table, before `DROP TABLE`. A
  table with a row that arrived between the first count and the detach is
  attached again and reported as kept. The same rule applies to a partition
  with a pending detach (the run finishes the detach first) and to an expired
  table that an earlier run detached.
- No step takes an `ACCESS EXCLUSIVE` lock on `webhook_deliveries`.

A delivery has no time limit, so **a partition with an unfinished row stays
until the row ends**: it is delivered, it reaches the retry limit, it gets a
permanent refusal, or an operator abandons it. The rows of a lane whose
endpoint is blocked, and of a subscription that the application removed, end
only by the abandon. Alert on a report with a kept partition, then end the old
rows with `message-broker:webhook:abandon --before=<date>` (see
[docs/CLI_REFERENCE.md](https://github.com/freyr/message-broker/blob/main/docs/CLI_REFERENCE.md)). The next run of the
retention drops the partition.

Run the retention once a day, for example:

```php
$retention->ensureCapacity(3);
$report = $retention->purge(7);
if ($report->hasKept()) {
    // alert: a partition with unfinished rows passed the retention period;
    // end the old rows with message-broker:webhook:abandon
}
```

## With pg_partman

pg_partman can create the partitions. Its default name for a daily partition,
`webhook_deliveries_pYYYYMMDD`, is the name this library uses, so
`ensureCapacity()` skips the partitions that pg_partman made, and `purge()`
drops them when they expire. Use the configuration of the outbox table:

- **No default partition:** `create_parent(..., p_default_table := false)`.
  With a default partition, `DETACH PARTITION ... CONCURRENTLY` fails.
- **No retention:** keep `retention` NULL in `part_config`. pg_partman
  retention runs a plain `DETACH PARTITION`, which takes `ACCESS EXCLUSIVE` on
  the parent and does not check for unfinished rows. Let `purge()` drop the
  partitions.
- **UTC:** run the database in UTC, so the day boundaries of pg_partman agree
  with the UTC days of this library.

## Alert: the age of the oldest due row for each lane

A due row waits for a claim. When the age of the oldest due row of a lane
grows, the workers do not keep up, or the circuit breaker holds the lane. A
leased row is not due until its lease ends, so a stopped worker shows here
after the lease.

```sql
SELECT lane,
       EXTRACT(EPOCH FROM CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3)) - MIN(available_at)) AS oldest_due_seconds,
       COUNT(*) AS due_rows
FROM webhook_deliveries
WHERE completed_at IS NULL
  AND available_at <= CAST(now() AT TIME ZONE 'UTC' AS TIMESTAMP(3))
GROUP BY lane
ORDER BY oldest_due_seconds DESC;
```

The query reads the partial index, which holds only the unfinished rows. A
lane with no due row has no result row: its age is 0. Alert when
`oldest_due_seconds` of a lane passes a limit that you choose from the retry
delays and the load, and on a report of `purge()` with a kept partition.

## Flow control tables

The rate limiter and the circuit breaker of the core
([docs/WEBHOOKS.md](https://github.com/freyr/message-broker/blob/main/docs/WEBHOOKS.md#flow-control)) keep their state in
two small tables, with no partitions and no retention.
`message-broker:webhook:setup` creates them. Construct each store with the
dedicated connection of the delivery worker, which is in no transaction of the
application. A database error of a store stops the worker: the flow control
never fails open.

### `webhook_rate_limit`

One row for each token bucket: the global bucket and each lane with a limit.

| Column | Type | Purpose |
|---|---|---|
| `name` | `VARCHAR(64)`, the key | `''` is the global bucket; else the lane ID. A lane ID has 1 to 64 characters, so `''` cannot be a lane. |
| `tokens` | `DOUBLE PRECISION` | The permits in the bucket at `refilled_at`. |
| `refilled_at` | `TIMESTAMPTZ` | The time of the last refill calculation, in whole milliseconds. |

The table has `fillfactor` 50 and no index besides the key, so each update can
be a HOT update. The rows hold no rate and no burst capacity: each call gives
them. A lane with no limit has no row. The row of a removed lane, or of a lane
that lost its limit, stays and has no effect.

`PostgreSqlRateLimiter` does one call in these steps:

1. The buckets of the call are `''` (only when the global rate is not `null`)
   and the lanes of the call with a limit. A call with no bucket divides the
   total and does not touch the database.
2. In autocommit, one `INSERT ... ON CONFLICT (name) DO NOTHING` adds the rows
   that do not exist, sorted by `name COLLATE "C"`, each with the burst
   capacity of its rate (a new bucket starts full). It commits before any lock
   of the call. The store skips it for the names that it already saw in the
   process; that cache is a hint only.
3. One transaction locks the rows: `SELECT ... ORDER BY name COLLATE "C" FOR
   UPDATE`. When a row is missing (a different connection deleted it), the
   store rolls back, inserts the rows again and repeats the lock one time; a
   second miss throws.
4. PHP computes the refill and the division (`PermitDivision`) with the
   database time (`clock_timestamp()`). A database time earlier than
   `refilled_at` gives a refill of 0 and keeps `refilled_at`.
5. One `UPDATE ... FROM jsonb_to_recordset(...)` writes all rows, and the
   transaction commits. `giveBack()` uses the same steps.

**The lock order.** All calls lock their rows in the order of the name
(`COLLATE "C"`, the byte order). `''` sorts first, so the global row is
always the first lock: the calls of all workers take the global row one after
the other, and two calls never wait for each other in a circle. With a `null`
global rate, the store does not insert, lock or update `''`, and the lane rows
keep the same order. The insert of new rows commits before the lock, so two
workers that insert the same new rows wait for each other in one order only.
A functional test runs two sessions with opposite lane orders 1,000 times,
with rows that exist and with new rows, and gets no deadlock.

### `webhook_lanes`

One row for each lane that had a result. The times are `TIMESTAMP(3)` in UTC,
as in `webhook_deliveries`.

| Column | Purpose |
|---|---|
| `lane` | The lane ID; the key. |
| `window_started_at` | The start of the present count window. |
| `requests` | The requests with a result in the window. |
| `failures` | The results in the window that count as a failure. |
| `open_until` | The end of the last timed opening. `NULL` when the lane never left circulation, or after a close. |
| `opened_count` | The times that the lane left circulation, also by a block, for the operator. |
| `consecutive_openings` | The openings in a row with no recovery between them. |
| `blocked_at` | The time of the block. `NULL` when the lane is not blocked. |

`PostgreSqlCircuitBreaker` uses the database time: `now()` in UTC, cast to
`TIMESTAMP(3)`.

- `openLanes()` is one read: `blocked_at IS NOT NULL OR open_until > now`.
- `record()` is one statement in autocommit. It locks the rows of its lanes
  with `SELECT ... ORDER BY lane COLLATE "C" FOR UPDATE` (the order of the
  in-memory fake), computes the window, count, trip, block, ignore and
  recovery rules from the locked state, writes the rows, and returns the
  lanes that left circulation, returned and were blocked. Under the lock, the
  statement reads the latest state of each row, so each trip gives one
  "left" (or "blocked") and one "returned" in all workers together.
- A lane with no row gets one before the statement: one `INSERT ... ON
  CONFLICT (lane) DO NOTHING` in autocommit, sorted by `lane COLLATE "C"`,
  with a new window. The store skips it for the lanes that it already saw.
  When a row is missing at the lock, the statement writes nothing; the store
  inserts the rows again and repeats the statement one time, and a second
  miss throws.
- `close()` and `all()` are one statement each.

A functional test runs two sessions that call `record()` with the same lanes
in opposite orders 1,000 times, with rows that exist and with new rows, and
gets no deadlock.

The row of a removed subscription stays; it has no effect, because the lane is
not in the lane list. An alert on a blocked lane:

```sql
SELECT lane, blocked_at, opened_count FROM webhook_lanes WHERE blocked_at IS NOT NULL;
```

`message-broker:webhook:lanes` shows the same state, and `--close=<lane>` ends
an opening or a block ([docs/CLI_REFERENCE.md](https://github.com/freyr/message-broker/blob/main/docs/CLI_REFERENCE.md#message-brokerwebhooklanes)).

### pgbouncer

Both stores use only row locks of one transaction (the rate limiter) or of one
statement (the circuit breaker), and the database time. Each statement takes
the names or the lanes as one `jsonb` parameter, so its text is the same for
each number of lanes, and pdo_pgsql and the pooler keep one prepared statement
for each text. Thus both work behind pgbouncer in transaction mode, with
`max_prepared_statements` above 0. The CI job `pgbouncer` runs their contract
tests ([CONNECTION_POOLING.md](CONNECTION_POOLING.md)).

### Rollback

Configure `NoRateLimit` and `NoCircuitBreaker`. The tables stay; drop
`webhook_rate_limit` and `webhook_lanes` by hand when you do not need them.
A move to a different rate limit store needs no data move: the new store
starts its buckets full.
