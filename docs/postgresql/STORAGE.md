# PostgreSQL storage

The PostgreSQL adapter implements the storage ports of the core on PostgreSQL
14 or later, with plain PDO. The classes are in the namespace
`Freyr\MessageBroker\Adapter\PostgreSql\`. This document describes the tables,
the schema setup, the classes and their wiring, and the test support. The
partitions and the retention of the outbox are in
[RETENTION.md](RETENTION.md). The pooler rules are in
[CONNECTION_POOLING.md](CONNECTION_POOLING.md). The ports are listed in
[../core/PORTS.md](../core/PORTS.md).

## Tables

### `outbox_messages`

`outbox_messages` is a PostgreSQL table partitioned by range on `created_at`
(PostgreSQL 14+ only). It has one partition per UTC day, named
`outbox_messages_pYYYYMMDD`, and no default partition: an insert for a day
with no partition fails. Columns: `id` UUID, `lane`
VARCHAR(64), `message_name` VARCHAR(255), `body` BYTEA (the final wire bytes,
payload only), `headers` JSONB (the produce-time headers), `created_at`
TIMESTAMP(3), `available_at` TIMESTAMP(3), `attempts` INTEGER and
`published_at` TIMESTAMP(3) NULL. The primary key is `(id, created_at)`. A
partial index on `(lane, available_at)` where `published_at IS NULL` serves
the claim query.

The relay only sets `published_at`. Rows leave the table when
`message-broker:outbox:maintain` drops an expired daily partition. A partition
that still holds unpublished rows is kept (and the command exits with code 1),
unless the option `--drop-unpublished` is set. See
[../core/CLI_REFERENCE.md](../core/CLI_REFERENCE.md) and
[RETENTION.md](RETENTION.md).

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
`attempts` INTEGER, `failed_at` TIMESTAMP(3) and `replayed_at` TIMESTAMP(3)
NULL. The table is the same for every transport. The operator commands are in
[../core/DLQ_OPERATIONS.md](../core/DLQ_OPERATIONS.md).

## Schema setup

`PostgreSqlSchemaInstaller` implements the core port `SchemaInstaller`.
`install(int $daysAhead)` creates the missing tables and indexes and the
daily partitions from today to today plus `$daysAhead`. It is safe to repeat,
because it skips the partitions that are already attached. `sql(int $daysAhead)`
returns the DDL of a new database. Its partition DDL is not idempotent
(`ATTACH`), so use it for a new database only. The console command
`message-broker:setup:schema` calls these two methods (see
[../core/CLI_REFERENCE.md](../core/CLI_REFERENCE.md)).

```php
use Freyr\MessageBroker\Console\SetupSchemaCommand;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlSchemaInstaller;

$application->add(new SetupSchemaCommand(new PostgreSqlSchemaInstaller($pdo)));
```

## Classes and wiring

| Class | Implements | Constructor |
|---|---|---|
| `PostgreSqlOutboxStore` | `OutboxStore` | `PDO $pdo` |
| `PostgreSqlDeduplicationStore` | `DeduplicationStore` | `PDO $pdo`, `int $deleteChunkSize = 10_000` |
| `PostgreSqlDeadLetterStore` | `DeadLetterStore` | `PDO $pdo` |
| `PdoTransaction` | `Transaction` | `PDO $pdo` |
| `PostgreSqlSchemaInstaller` | `SchemaInstaller` | `PDO $pdo` |
| `PostgreSqlOutboxRetention` | `OutboxRetention` | `PDO $pdo` |

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
use Freyr\MessageBroker\Adapter\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;

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
[../core/CLI_REFERENCE.md](../core/CLI_REFERENCE.md)).

## Test support

The trait `Freyr\MessageBroker\Adapter\PostgreSql\Testing\PostgreSqlTestSupport`
is the PostgreSQL set-up of the functional tests. It opens one PDO connection,
recreates the schema for each test class, and clears the rows before each test.
It reads `POSTGRES_DSN`, `POSTGRES_USER` and `POSTGRES_PASSWORD`. It refuses to
run when the database name does not contain `_test`, because it drops the
tables. Infection sets `TEST_TOKEN` for each parallel worker, and the trait then
uses the database `<name>_t<token>`, which it creates on first use. The
transport-neutral part is the trait `TestIsolation` of the core (see
[../core/TESTING.md](../core/TESTING.md)).
