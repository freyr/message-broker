# freyr/message-broker-storage-postgresql

This package is the PostgreSQL storage adapter of the Freyr Message Broker library,
namespace `Freyr\MessageBroker\Storage\PostgreSql\`.

> The repository `freyr/message-broker-storage-postgresql` is a read-only copy of the folder
> `storage/postgresql/` of [freyr/message-broker](https://github.com/freyr/message-broker).
> Development, issues and pull requests are in freyr/message-broker. A
> release has the tag `storage-postgresql-v<version>` in freyr/message-broker
> and the tag `v<version>` in `freyr/message-broker-storage-postgresql`.

The adapter implements the storage ports of the core with plain PDO: the
outbox store, the deduplication store, the dead-letter store, the transaction,
the schema installer and the outbox retention. The outbox table is
partitioned by day.

## Requirements

- PHP 8.4 or later, with `ext-pdo` and `ext-pdo_pgsql`
- PostgreSQL 14 or later
- `freyr/message-broker` ^0.15 (the core), `symfony/clock` ^8.1,
  `symfony/uid` ^7.4 or ^8.1

## Install

```bash
composer require freyr/message-broker-storage-postgresql
```

## Minimal wiring

```php
use Freyr\MessageBroker\Storage\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlSchemaInstaller;
use Freyr\MessageBroker\Console\SetupSchemaCommand;

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$application->add(new SetupSchemaCommand(new PostgreSqlSchemaInstaller($pdo)));

$outbox = new PostgreSqlOutboxStore($pdo);
$transaction = new PdoTransaction($pdo);
$deduplication = new PostgreSqlDeduplicationStore($pdo);
$deadLetters = new PostgreSqlDeadLetterStore($pdo);
```

The relay store can delete published rows instead of the `published_at` mark:
`new PostgreSqlOutboxStore($workerPdo, PublishedRows::Delete)` (default `PublishedRows::Mark`). See
[docs/STORAGE.md](docs/STORAGE.md#published-rows-mark-or-delete).

## Documents

- [Storage](docs/STORAGE.md): tables, schema setup, classes
- [Retention](docs/RETENTION.md): partitions, `outbox:maintain`, pg_partman
- [Connection pooling](docs/CONNECTION_POOLING.md)
- [Webhook delivery storage](docs/WEBHOOKS.md): `webhook_deliveries`, the delivery store, retention, pg_partman, and the flow control tables with `PostgreSqlRateLimiter` and `PostgreSqlCircuitBreaker`
- [Ports of the core](https://github.com/freyr/message-broker/blob/main/docs/PORTS.md)
