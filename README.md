# Freyr Message Broker

Standalone PHP messaging library: transactional outbox with total per-lane ordering, consumer-side deduplication and dead-lettering with replay, and per-transport producers and consumers — AMQP and Kafka. Plain PDO storage (MySQL, PostgreSQL), no framework coupling.

## What it is

True **exactly-once** processing is rare and genuinely hard to build in PHP — it normally means hand-rolling a transactional outbox, consumer-side deduplication, and dead-lettering for every project. Freyr Message Broker packages that machinery as a reusable library: produce inside your database transaction, and the message reaches its transport with **at-least-once delivery + consumer-side deduplication** — exactly-once *processing* — with poison messages dead-lettered and replayable.

It is **multi-transport by design** so you adopt it within your existing infrastructure instead of standing up a new broker. Each transport is met on its own terms:

- **AMQP (RabbitMQ)** — competing-consumer queues, routed by message type. Best-effort per-key ordering (consistent-hash + single active consumer) is a **postponed** lane mode — not yet built; AMQP does **not** offer strict FIFO.
- **Kafka** — the partitioned log via `ext-rdkafka` or Debezium CDC (planned). This is the transport for **strict** per-key FIFO.

Strict per-key FIFO is **a Kafka capability**, not the point of the library — one of the things its exactly-once core makes possible on the partitioned log. AMQP's per-key ordering, when built, will be best-effort only; strict ordering that survives failures is routed to Kafka.

> **Status: early development.** Ground-up rewrite in progress (formerly a Symfony Messenger bundle, available as `v0.x` tags). No stable release yet; every API may change without notice.

## Requirements

- PHP ≥ 8.4
- MySQL 8+ or PostgreSQL 13+
- A supported transport client, e.g. `php-amqplib/php-amqplib` for AMQP

## Development

```bash
docker compose up -d --wait   # MySQL, PostgreSQL, RabbitMQ
make test                     # phpunit (functional tests against real services)
make phpstan
make cs-check
```

`make test` runs the suite against MySQL; `make test-pgsql` runs it against PostgreSQL; `make test-all` runs both. Functional tests pass on either engine (the `DB_ENGINE` env var selects it).

### Kafka transport

A native `ext-rdkafka` relay and consumer (`src/Transport/Kafka/`). Register a
relay/consumer per lane exactly like AMQP — `RelayRunCommand`/`ConsumeCommand`
take `lane => fn()` closures. The relay produces `message_key → partition` under
an idempotent `murmur2_random` producer (strict per-key FIFO); the consumer
commits offsets only after the dedup/dispatch transaction (at-least-once +
dedup = exactly-once processing). Kafka functional tests run on MySQL and need
the `kafka` compose service (`KAFKA_BROKERS`, already wired).

## License

MIT
