# PostgreSQL storage

The PostgreSQL adapter implements the storage ports of the core on PostgreSQL
14 or later, with plain PDO. The classes are in the namespace
`Freyr\MessageBroker\Storage\PostgreSql\`. This document describes the tables,
the schema setup, the classes and their wiring, and the test support. The
partitions and the retention of the outbox are in
[RETENTION.md](RETENTION.md). The table `webhook_deliveries` of the webhook
queue, with its store, schema installer and retention, is in
[WEBHOOKS.md](WEBHOOKS.md). The pooler rules are in
[CONNECTION_POOLING.md](CONNECTION_POOLING.md). The ports are listed in
[docs/PORTS.md](https://github.com/freyr/message-broker/blob/main/docs/PORTS.md).

## Tables

### `outbox_messages`

`outbox_messages` is a PostgreSQL table partitioned by range on `created_at`
(PostgreSQL 14+ only). It has one partition per UTC day, named
`outbox_messages_pYYYYMMDD`, and no default partition: an insert for a day
with no partition fails. Columns: `id` UUID, `lane`
VARCHAR(64), `message_name` VARCHAR(255), `body` BYTEA (the final wire bytes,
payload only), `headers` JSONB (the produce-time headers), `created_at`
TIMESTAMP(3), `available_at` TIMESTAMP(3), `attempts` INTEGER,
`published_at` TIMESTAMP(3) NULL and `abandoned_at` TIMESTAMP(3) NULL. The
primary key is `(id, created_at)`. A partial index `idx_outbox_pending` on
`(lane, available_at)` where `published_at IS NULL` serves the claim query.

`abandoned_at` marks a row that an operator abandoned with
`message-broker:outbox:abandon` (UTC, as each timestamp of the table; `NULL`
means not abandoned). The reason is not in the row: it goes to the log and to
the event. The claim, `markPublished()`, `deletePublished()` and `scheduleRetry()` have the
condition `abandoned_at IS NULL`. The index keeps its predicate
`published_at IS NULL`: the claim still implies it, so PostgreSQL still uses
the index. An abandoned row stays in the index until retention drops its
partition, so each claim of its lane reads it and skips it. Abandoned rows are
few, because an operator makes each one by hand.

With the default `PublishedRows::Mark`, the relay only sets `published_at`
(see [Published rows: mark or delete](#published-rows-mark-or-delete)). Rows leave the table when
`message-broker:outbox:maintain` drops an expired daily partition. A partition
that still holds unpublished rows that are not abandoned is kept (and the
command exits with code 1), unless the option `--drop-unpublished` is set. An
abandoned row does not keep its partition. See
[docs/CLI_REFERENCE.md](https://github.com/freyr/message-broker/blob/main/docs/CLI_REFERENCE.md) and
[RETENTION.md](RETENTION.md).

#### Published rows: mark or delete

`PostgreSqlOutboxStore` takes a second, optional argument, the enum
`PublishedRows`. It selects what the drain does with a row after a confirmed
publish:

```php
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Storage\PostgreSql\PublishedRows;

// The store of a relay worker that deletes published rows.
$outbox = new PostgreSqlOutboxStore($workerPdo, PublishedRows::Delete);

// From configuration: the values are 'mark' and 'delete'.
$outbox = new PostgreSqlOutboxStore($workerPdo, PublishedRows::from($config['outbox_published_rows']));
```

| | `PublishedRows::Mark` (default) | `PublishedRows::Delete` |
| --- | --- | --- |
| What the drain writes in the claim transaction | `UPDATE ... SET published_at` | `DELETE` |
| Published rows | Stay until `outbox:maintain` drops their daily partition. | Leave the table at once. The table holds only open rows. |
| Record of published messages | Each published row, for the retention period. | None. |
| Dead rows | One for each message. The update is never a HOT update, because `published_at` is in the predicate of `idx_outbox_pending`: it writes a new row version and index entries. VACUUM removes them, or the partition drop removes them with the partition. | One for each message. VACUUM removes them. |
| `outbox:maintain` | Creates the partitions ahead and drops the expired ones. | The same. An expired partition holds only unpublished or abandoned rows, or none. |

Only the store of the relay uses the setting. The store of the producer and
the operator commands (`outbox:abandon`, `outbox:failing`) work the same with
both values, so they can keep the default. One table can have relays of both
kinds: the delete has the conditions of the mark (`published_at IS NULL AND
abandoned_at IS NULL`), so a delete relay never removes a row that a mark relay
published.

**Which one to choose.** Measured with `make bench-outbox-cleanup` (script
`tools/outbox-cleanup-bench.php`): PostgreSQL 16 with the default settings,
the library `Relay` and `AmqpMessagePublisher` with confirms, batch 500,
payload 200 bytes, all containers on one laptop. CPU is the CPU time of the
whole PostgreSQL container, with autovacuum, the WAL writer and the
checkpointer.

| Load | `Mark` | `Delete` |
| --- | --- | --- |
| Backlog: 1,000,000 waiting rows, 4 workers, 3 runs, medians (`drain rows=1000000`; autovacuum off for the partition; `Mark` with no VACUUM before the drop, `Delete` with its VACUUM; both with the drop) | 15.0 µs CPU for each row, 942 MB WAL | 9.6 µs CPU for each row, 440 MB WAL |
| Steady: 2,000 rows/s, one row in each insert transaction, 2 workers, autovacuum on, 4 runs of 120 s (`steady`) | median 47.6 s CPU (46.6 to 53.6), 203 MB WAL | median 63.0 s CPU (51.3 to 76.7), 156 MB WAL |

Under the steady load, the table of `Delete` is almost empty. After an
autovacuum, PostgreSQL sometimes planned the claim as a bitmap scan or a
sequential scan (in 3 of 4 runs). A bitmap scan reads each dead index entry and
does not mark it as dead, so each claim read the same entries again; a
sequential scan reads each page of the partition. `Mark`
kept the index scan in each run, because its partition holds many live rows.

Keep `Mark` when you need the published rows, or when the load is steady.
`Delete` fits a lane that mostly drains backlogs and needs no record of the
published messages. With `Delete`, watch the dead rows of the current partition
(`n_dead_tup` in `pg_stat_user_tables`) and the plan of the claim. With both
values, a long-open transaction stops VACUUM and slows the claim. Run the
benchmark with your own rate and payload before a change:
`make bench-outbox-cleanup ARGS="steady rate=<rows/s> seconds=300"`.

### `message_deduplication`

The consumer inserts one row for each message and consumer. Columns:
`message_id` VARCHAR(255), `consumer` VARCHAR(128), `message_name`
VARCHAR(255) and `created_at` TIMESTAMP(3). The primary key is
`(message_id, consumer)`. The index `idx_dedup_created_at` on `created_at`
serves the cleanup.

### `dead_letters`

Columns: `id` UUID (primary key), `source` VARCHAR(255), `message_id`
VARCHAR(255), `message_name` VARCHAR(255), `body` BYTEA, `headers` JSONB,
`error_class` VARCHAR(255), `error_message` TEXT, `error_trace` TEXT,
`attempts` INTEGER, `failed_at` TIMESTAMP(3), `replayed_at` TIMESTAMP(3)
NULL and `replayable` BOOLEAN NOT NULL DEFAULT TRUE (`false` when the body is
the raw wire bytes). The table is the same for every transport. The operator
commands are in [docs/DLQ_OPERATIONS.md](https://github.com/freyr/message-broker/blob/main/docs/DLQ_OPERATIONS.md).

The partial unique index `uq_dead_letters_open` on (`message_id`, `source`)
`WHERE replayed_at IS NULL` permits one open row for each message and work
queue. `PostgreSqlDeadLetterStore::store()` is one statement:
`INSERT ... SELECT ... WHERE NOT EXISTS` (a row with the same `id`) `ON
CONFLICT (message_id, source) WHERE replayed_at IS NULL DO UPDATE` of the
columns `message_name`, `error_class`, `error_message`, `error_trace`,
`attempts`, `failed_at`, `body`, `headers` and `replayable`. Thus a store of a
known `id` changes nothing, and an open row keeps its `id`. A replayed row is
outside the index, so a failure after a replay inserts a new row. Two workers
that store the same pair at the same time give one row: PostgreSQL serializes
the two statements on the index entry.

The statement names the index. When the index is absent (an existing table
that did not get the migration of [UPGRADE.md](https://github.com/freyr/message-broker/blob/main/UPGRADE.md)), each
store fails with `there is no unique or exclusion constraint matching the ON
CONFLICT specification`, and each failed delivery stays unacked. The filters
of `list()`, `count()`, `ids()`, `stats()` and `purge()` use no index: they
scan the table, which holds failures only.

## Schema setup

`PostgreSqlSchemaInstaller` implements the core port `SchemaInstaller`.
`install(int $daysAhead)` creates the missing tables and indexes and the
daily partitions from today to today plus `$daysAhead`. It is safe to repeat,
because it skips the partitions that are already attached. `sql(int $daysAhead)`
returns the DDL of a new database. Its partition DDL is not idempotent
(`ATTACH`), so use it for a new database only. The console command
`message-broker:setup:schema` calls these two methods (see
[docs/CLI_REFERENCE.md](https://github.com/freyr/message-broker/blob/main/docs/CLI_REFERENCE.md)).

```php
use Freyr\MessageBroker\Console\SetupSchemaCommand;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlSchemaInstaller;

$application->add(new SetupSchemaCommand(new PostgreSqlSchemaInstaller($pdo)));
```

## Classes and wiring

| Class | Implements | Constructor |
|---|---|---|
| `PostgreSqlOutboxStore` | `OutboxStore` | `PDO $pdo`, `PublishedRows $publishedRows = PublishedRows::Mark` |
| `PostgreSqlDeduplicationStore` | `DeduplicationStore` | `PDO $pdo`, `int $deleteChunkSize = 10_000` |
| `PostgreSqlDeadLetterStore` | `DeadLetterStore` | `PDO $pdo` |
| `PdoTransaction` | `Transaction` | `PDO $pdo` |
| `PostgreSqlSchemaInstaller` | `SchemaInstaller` | `PDO $pdo` |
| `PostgreSqlOutboxRetention` | `OutboxRetention` | `PDO $pdo` |
| `PostgreSqlDeliveryStore` | `Webhook\DeliveryStore` | `PDO $pdo` |
| `PostgreSqlDeliverySchemaInstaller` | `SchemaInstaller` | `PDO $pdo` |
| `PostgreSqlDeliveryRetention` | `Webhook\DeliveryRetention` | `PDO $pdo` |

Use the connections like this:

- **Producer.** Pass the connection of your application to
  `PostgreSqlOutboxStore`, so that the outbox row joins your transaction.
- **Relay.** Each relay worker owns a dedicated connection.
- **Consumer.** `PdoTransaction`, `PostgreSqlDeduplicationStore` and your
  handler use the same connection. Then the deduplication row and the work of
  the handler commit together.
- **Maintenance.** `PostgreSqlOutboxRetention::purge()` runs outside a
  transaction.

```php
use Freyr\MessageBroker\Storage\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$outbox = new PostgreSqlOutboxStore($pdo);
$transaction = new PdoTransaction($pdo);
$deduplication = new PostgreSqlDeduplicationStore($pdo);
$deadLetters = new PostgreSqlDeadLetterStore($pdo);
$retention = new PostgreSqlOutboxRetention($pdo);
```

## Deduplication cleanup

`PostgreSqlDeduplicationStore` deletes the old entries of
`message_deduplication` in chunks of 10,000 rows (constructor argument
`deleteChunkSize`); each chunk is a short statement that the index on
`created_at` serves, so the cleanup holds no long transaction. The command
`message-broker:dedup:cleanup` uses it (see
[docs/CLI_REFERENCE.md](https://github.com/freyr/message-broker/blob/main/docs/CLI_REFERENCE.md)).

## Test support

The trait `Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport`
is the PostgreSQL set-up of the functional tests. It opens one PDO connection,
recreates the schema for each test class (with `webhook_deliveries`), and
clears the rows before each test.
It reads `POSTGRES_DSN`, `POSTGRES_USER` and `POSTGRES_PASSWORD`. It refuses to
run when the database name does not contain `_test`, because it drops the
tables. Infection sets `TEST_TOKEN` for each parallel worker, and the trait then
uses the database `<name>_t<token>`, which it creates on first use. The
transport-neutral part is the trait `TestIsolation` of the core (see
[docs/TESTING.md](https://github.com/freyr/message-broker/blob/main/docs/TESTING.md)).
