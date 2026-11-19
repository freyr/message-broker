# Freyr Message Broker

Standalone PHP messaging library: transactional outbox on a partitioned PostgreSQL table, consumer-side deduplication and dead-lettering with replay, and an AMQP producer and consumer. Messages are JSON. Plain PDO storage on PostgreSQL, no framework coupling.

## What it is

True **exactly-once** processing is rare and genuinely hard to build in PHP — it normally means hand-rolling a transactional outbox, consumer-side deduplication, and dead-lettering for every project. Freyr Message Broker packages that machinery as a reusable library: produce inside your database transaction, and the message reaches its transport with **at-least-once delivery + consumer-side deduplication** — exactly-once *processing* — with poison messages dead-lettered and replayable.

It runs over **AMQP (RabbitMQ)** so you adopt it within your existing infrastructure instead of standing up a new broker: competing-consumer queues, routed by message name. It gives no ordering guarantee: rows publish in any order, and consumers must be order-insensitive.

> **Status: 0.x.** No stable release yet; every API may change between minor versions.
> A minor release (0.**x**.0) holds a major change and can break code. A patch release
> (0.x.**y**) holds smaller improvements and fixes.

## Packages

The library has three packages. **Today, one Composer package
(`freyr/message-broker`) holds all three.** Separate Composer packages and
separate repositories are planned. Each package has its own folder of
documents, so that the documents move with the code.

| Package | Folder in this repo | What it holds | Documents |
|---|---|---|---|
| Core (`freyr/message-broker`) | `src/broker/` | The ports, the relay loop, the delivery processor, retry and dead letters, the wire format, the console commands and the test kit. | [docs/core/](docs/core/) |
| PostgreSQL adapter (`freyr/message-broker-postgresql`) | `src/adapter/postgresql/` | The outbox, deduplication and dead-letter stores, the transaction, the schema installer and the outbox retention. | [docs/postgresql/](docs/postgresql/) |
| AMQP adapter (`freyr/message-broker-amqp`) | `src/adapter/amqp/` | The AMQP publisher and consumer, the wait-queue retry and the config classes. | [docs/amqp/](docs/amqp/) |

The manifest and the README of each future package are in `packages/<name>/`.

## Install

```bash
composer require freyr/message-broker
```

This installs the code of all three packages. It replaces `freyr/message-broker-postgresql` and `freyr/message-broker-amqp`.

Requires PHP 8.4 or 8.5 (tested in CI on both), `ext-pdo_pgsql`, and PostgreSQL 14+. Install the pieces you use:

| Need | Add |
|---|---|
| AMQP transport | `composer require php-amqplib/php-amqplib` |
| Graceful shutdown | `ext-pcntl` (relay/consumer SIGTERM handling) |

## Quickstart

Define a message, produce it inside your own database transaction, run the
schema setup, relay it over AMQP, and consume it with deduplication
and retry — five steps, one lane, end to end. See
[docs/QUICKSTART.md](docs/QUICKSTART.md) for a runnable produce → relay →
consume example.

## The lane concept

One outbox table holds every produced message; each row is tagged with a
lane, a named drain of that table. Lanes isolate workloads from each other.
Any number of identical relay workers drain one lane in parallel; scale by
adding workers to the lane. The relay marks rows published and never deletes
them. The command `message-broker:outbox:maintain` drops daily partitions
after the retention period (default 7 days). A partition with unpublished rows is kept, and the command exits non-zero, unless `--drop-unpublished` is set — see [docs/core/RELAY.md](docs/core/RELAY.md) and
[docs/postgresql/RETENTION.md](docs/postgresql/RETENTION.md).

## Documentation

- [Quickstart](docs/QUICKSTART.md) — produce → relay → consume, end to end (uses all three packages)

Core (`docs/core/`):

- [Relay and lanes](docs/core/RELAY.md) — lanes, the relay loop, failure handling, backoff
- [Delivery processor and retry](docs/core/CONSUMER.md) — the exactly-once algorithm, the retry policy
- [Ports](docs/core/PORTS.md) — the ports and the classes that implement them
- [CLI Reference](docs/core/CLI_REFERENCE.md) — every shipped console command
- [DLQ Operations](docs/core/DLQ_OPERATIONS.md) — inspecting, replaying, and purging dead letters
- [Production](docs/core/PRODUCTION.md) — deployment, graceful shutdown, observability, scaling
- [Testing](docs/core/TESTING.md) — contract test cases for adapter authors, in-memory fakes for applications

PostgreSQL adapter (`docs/postgresql/`):

- [Storage](docs/postgresql/STORAGE.md) — the tables, the schema setup, the classes and their wiring
- [Retention](docs/postgresql/RETENTION.md) — outbox partitions, `outbox:maintain`, pg_partman
- [Connection pooling](docs/postgresql/CONNECTION_POOLING.md) — pgbouncer

AMQP adapter (`docs/amqp/`):

- [Transport](docs/amqp/TRANSPORT.md) — routing, config classes, the dedicated channel, the publisher, the topology
- [Consumer](docs/amqp/CONSUMER.md) — the consumer, the TTL wait queues, the dead-letter exchange
- [Dead letters](docs/amqp/DEAD_LETTERS.md) — the import of a dead letter queue, the read of `x-death`, the permissions

## License

MIT
