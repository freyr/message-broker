# Webhook delivery storage

This document describes the PostgreSQL part of the webhook queue: the table
`webhook_deliveries`, its index, the delivery store, the retention, the
pg_partman configuration and the alert query. The model of the queue, the
ports and the delivery loop are in [../core/WEBHOOKS.md](../core/WEBHOOKS.md).
The other tables are in [STORAGE.md](STORAGE.md). The outbox retention, which
has the same rules, is in [RETENTION.md](RETENTION.md).

| Class | Implements | Constructor |
|---|---|---|
| `PostgreSqlDeliveryStore` | `Webhook\DeliveryStore` | `PDO $pdo` |
| `PostgreSqlDeliverySchemaInstaller` | `Storage\SchemaInstaller` | `PDO $pdo` |
| `PostgreSqlDeliveryRetention` | `Webhook\DeliveryRetention` | `PDO $pdo` |

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
| `created_at` | `TIMESTAMP(3)` | The partition key, and the start of the maximum age. |
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
small. The claim of expired rows and the alert query read it too.

## Schema setup

`PostgreSqlDeliverySchemaInstaller` creates the table, the index and the daily
partitions. It is apart from `PostgreSqlSchemaInstaller`, so an application
that does not use webhooks runs no DDL for it. `install(int $daysAhead)`
creates what is missing, with the partitions from today to today plus
`$daysAhead`, and is safe to repeat. `sql(int $daysAhead)` returns the DDL of
a new database; its partition DDL is not idempotent (`ATTACH`). The dead
letters of the deliveries go to `dead_letters`, which
`PostgreSqlSchemaInstaller` creates.

The console commands `message-broker:webhook:setup` and
`message-broker:webhook:maintain` take these classes (see
[../core/CLI_REFERENCE.md](../core/CLI_REFERENCE.md)). Without the commands,
call `install()` from your deployment, and `PostgreSqlDeliveryRetention` from a
daily job (below).

```php
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeliveryRetention;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeliverySchemaInstaller;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeliveryStore;

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
- **Claim.** `claim()` is one statement. The lanes come as a JSON array with
  their position (`jsonb_array_elements_text ... WITH ORDINALITY`). For each
  lane, a `LATERAL` subquery reads `idx_webhook_deliveries_due` from the lane
  start, takes at most the row limit of each lane with `FOR UPDATE SKIP
  LOCKED`, and the total limit then cuts the rows in the order of the lane
  positions. The `UPDATE` sets `available_at` to the lease. In autocommit
  mode the statement is its own transaction, so the lease commits before the
  method returns, and concurrent workers skip the rows of each other and do not
  wait. A lane that is two times in the list counts one time.
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
- **Expired rows.** `claimExpired()` leases the due, unfinished rows of all
  lanes that are older than the cut, the oldest first.
- **Pooler.** The store holds no session state and no session lock, so it
  works behind pgbouncer in transaction mode (see
  [CONNECTION_POOLING.md](CONNECTION_POOLING.md)).

The plan of the claim over 20 lanes, with 10,000 due rows in one lane, is a
Nested Loop over the lane list, with an Index Scan on the partial index of
each partition and LockRows in each loop. The `UPDATE` finds the claimed rows
through the primary key index of each partition. A functional test checks
that the plan has no sequential scan.

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
  when its rows are final.
- The rows are counted again on the detached table, before `DROP TABLE`. A
  table with a row that arrived between the first count and the detach is
  attached again and reported as kept. The same rule applies to a partition
  with a pending detach (the run finishes the detach first) and to an expired
  table that an earlier run detached.
- No step takes an `ACCESS EXCLUSIVE` lock on `webhook_deliveries`.

The delivery loop makes each row final before its partition expires: it
refuses to start when the maximum age plus the longest retry delay is not
shorter than the retention period (default 7 days). Thus a kept partition
shows a fault: alert on a report with a kept partition.

Run the retention once a day, for example:

```php
$retention->ensureCapacity(3);
$report = $retention->purge(7);
if ($report->hasKept()) {
    // alert: a partition with unfinished rows passed the retention period
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
