# Freyr Message Broker

Standalone PHP messaging library: transactional outbox with total per-lane ordering, consumer-side deduplication and dead-lettering with replay, and per-transport producers and consumers — AMQP and Kafka. Plain PDO storage (MySQL, PostgreSQL), no framework coupling.

## What it is

True **exactly-once** processing is rare and genuinely hard to build in PHP — it normally means hand-rolling a transactional outbox, consumer-side deduplication, and dead-lettering for every project. Freyr Message Broker packages that machinery as a reusable library: produce inside your database transaction, and the message reaches its transport with **at-least-once delivery + consumer-side deduplication** — exactly-once *processing* — with poison messages dead-lettered and replayable.

It is **multi-transport by design** so you adopt it within your existing infrastructure instead of standing up a new broker. Each transport is met on its own terms:

- **AMQP (RabbitMQ)** — competing-consumer queues, routed by message name. Best-effort per-key ordering (consistent-hash + single active consumer) is a **postponed** lane mode — not yet built; AMQP does **not** offer strict FIFO.
- **Kafka** — the partitioned log via `ext-rdkafka` or Debezium CDC (planned). This is the transport for **strict** per-key FIFO.

Strict per-key FIFO is **a Kafka capability**, not the point of the library — one of the things its exactly-once core makes possible on the partitioned log. AMQP's per-key ordering, when built, will be best-effort only; strict ordering that survives failures is routed to Kafka.

> **Status: early development.** Ground-up rewrite in progress (formerly a Symfony Messenger bundle, available as `v0.x` tags). No stable release yet; every API may change without notice.

## Install

```bash
composer require freyr/message-broker
```

Requires PHP ≥ 8.4, `ext-pdo`, and MySQL 8+ or PostgreSQL 13+. Install the pieces you use:

| Need | Add |
|---|---|
| AMQP transport | `composer require php-amqplib/php-amqplib` |
| Kafka transport | `ext-rdkafka` (PECL) |
| Avro wire format | `composer require apache/avro` |
| Redis schema cache | `ext-redis` |
| Graceful shutdown | `ext-pcntl` (relay/consumer SIGTERM handling) |

## Quickstart

Define a message, produce it inside your own database transaction, run the
schema setup, relay it over AMQP or Kafka, and consume it with deduplication
and retry — five steps, one lane, end to end. See
[docs/QUICKSTART.md](docs/QUICKSTART.md) for a runnable produce → relay →
consume example.

## The lane concept

One outbox table holds every produced message; each row is tagged with a
lane, a named drain of that table. Exactly one relay process serves one lane
on one transport, which guarantees total in-order publishing per lane. For
order-insensitive workloads, AMQP also ships an opt-in competing relay that
trades that ordering guarantee for parallel throughput — see
[docs/TRANSPORTS.md](docs/TRANSPORTS.md).

## Documentation

- [Quickstart](docs/QUICKSTART.md) — produce → relay → consume, end to end
- [Transports](docs/TRANSPORTS.md) — AMQP and Kafka relay/consumer setup, lane modes
- [Schema and Avro](docs/SCHEMA_AVRO.md) — wire formats, schema registration, compatibility
- [CLI Reference](docs/CLI_REFERENCE.md) — every shipped console command
- [DLQ Operations](docs/DLQ_OPERATIONS.md) — inspecting, replaying, and purging dead letters
- [Production](docs/PRODUCTION.md) — observability, graceful shutdown, operational guidance

## License

MIT
