# freyr/message-broker-postgresql

This package is the PostgreSQL storage adapter of the Freyr Message Broker
library. It is not published separately yet. Today the repository
`freyr/message-broker` holds its code in `src/adapter/postgresql/`, and the
links below point to the documents in this repository.

The adapter implements the storage ports of the core with plain PDO: the
outbox store, the deduplication store, the dead-letter store, the transaction,
the schema installer and the outbox retention. The outbox table is
partitioned by day.

## Requirements

- PHP 8.4 or later, with `ext-pdo` and `ext-pdo_pgsql`
- PostgreSQL 14 or later
- `freyr/message-broker` ^0.12 (the core), `symfony/clock` ^8.1,
  `symfony/uid` ^7.4 or ^8.1

## Install

After the split:

```bash
composer require freyr/message-broker-postgresql
```

Today, `composer require freyr/message-broker` installs this code with the
core.

## Minimal wiring

```php
use Freyr\MessageBroker\Adapter\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlSchemaInstaller;
use Freyr\MessageBroker\Console\SetupSchemaCommand;

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$application->add(new SetupSchemaCommand(new PostgreSqlSchemaInstaller($pdo)));

$outbox = new PostgreSqlOutboxStore($pdo);
$transaction = new PdoTransaction($pdo);
$deduplication = new PostgreSqlDeduplicationStore($pdo);
$deadLetters = new PostgreSqlDeadLetterStore($pdo);
```

## Documents

- [Storage](../../docs/postgresql/STORAGE.md): tables, schema setup, classes
- [Retention](../../docs/postgresql/RETENTION.md): partitions, `outbox:maintain`, pg_partman
- [Connection pooling](../../docs/postgresql/CONNECTION_POOLING.md)
- [Ports of the core](../../docs/core/PORTS.md)
