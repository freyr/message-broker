# Freyr Message Broker

Standalone PHP messaging library: transactional outbox on a partitioned PostgreSQL table, consumer-side deduplication and dead-lettering with replay, and an AMQP producer and consumer. Plain PDO storage on PostgreSQL, no framework coupling.

## What it is

True **exactly-once** processing is rare and genuinely hard to build in PHP — it normally means hand-rolling a transactional outbox, consumer-side deduplication, and dead-lettering for every project. Freyr Message Broker packages that machinery as a reusable library: produce inside your database transaction, and the message reaches its transport with **at-least-once delivery + consumer-side deduplication** — exactly-once *processing* — with poison messages dead-lettered and replayable.

It runs over **AMQP (RabbitMQ)** so you adopt it within your existing infrastructure instead of standing up a new broker: competing-consumer queues, routed by message name. It gives no ordering guarantee: rows publish in any order, and consumers must be order-insensitive.

> **Status: early development.** No stable release yet; every API may change without notice.

## Install

```bash
composer require freyr/message-broker
```

Requires PHP ≥ 8.4, `ext-pdo_pgsql`, and PostgreSQL 14+. Install the pieces you use:

| Need | Add |
|---|---|
| AMQP transport | `composer require php-amqplib/php-amqplib` |
| Avro wire format | `composer require apache/avro` |
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
after the retention period (default 7 days), unpublished rows included — see [docs/TRANSPORTS.md](docs/TRANSPORTS.md) and
[docs/PRODUCTION.md](docs/PRODUCTION.md).

## Documentation

- [Quickstart](docs/QUICKSTART.md) — produce → relay → consume, end to end
- [Transports](docs/TRANSPORTS.md) — AMQP relay/consumer setup, lanes
- [Schema and Avro](docs/SCHEMA_AVRO.md) — wire formats, schema registration, compatibility
- [CLI Reference](docs/CLI_REFERENCE.md) — every shipped console command
- [DLQ Operations](docs/DLQ_OPERATIONS.md) — inspecting, replaying, and purging dead letters
- [Production](docs/PRODUCTION.md) — observability, graceful shutdown, operational guidance

## License

MIT
