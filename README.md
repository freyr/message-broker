# Freyr Message Broker

Standalone PHP messaging library: transactional outbox with total per-lane ordering, consumer-side deduplication and dead-lettering with replay, and per-transport producers and consumers — AMQP first, Kafka and SQS planned. Plain PDO storage (MySQL, PostgreSQL), no framework coupling.

## What it is

True **exactly-once** processing is rare and genuinely hard to build in PHP — it normally means hand-rolling a transactional outbox, consumer-side deduplication, and dead-lettering for every project. Freyr Message Broker packages that machinery as a reusable library: produce inside your database transaction, and the message reaches its transport with **at-least-once delivery + consumer-side deduplication** — exactly-once *processing* — with poison messages dead-lettered and replayable.

It is **multi-transport by design** so you adopt it within your existing infrastructure instead of standing up a new broker. Each transport is met on its own terms:

- **AMQP (RabbitMQ)** — competing-consumer queues today; strict per-key FIFO as an opt-in lane mode (under research).
- **Kafka** — the partitioned log via `ext-rdkafka` or Debezium CDC (planned).
- **SQS** — standard and FIFO queues (planned).

Strict FIFO is **one lane mode among these use-cases** — not the point of the library, but one of the things its exactly-once core makes possible.

> **Status: early development.** Ground-up rewrite in progress (formerly a Symfony Messenger bundle, available as `v0.x` tags). No stable release yet; every API may change without notice.

## Requirements

- PHP ≥ 8.4
- MySQL 8+ or PostgreSQL 13+ (PostgreSQL support in progress)
- A supported transport client, e.g. `php-amqplib/php-amqplib` for AMQP

## Development

```bash
docker compose up -d --wait   # MySQL, PostgreSQL, RabbitMQ
make test                     # phpunit (functional tests against real services)
make phpstan
make cs-check
```

## License

MIT
