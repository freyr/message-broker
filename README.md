# Freyr Message Broker

Standalone PHP messaging library: transactional outbox on a partitioned PostgreSQL table, consumer-side deduplication and dead-lettering with replay, and an AMQP producer and consumer. Messages are JSON. Plain PDO storage on PostgreSQL, no framework coupling.

## What it is

True **exactly-once** processing is rare and genuinely hard to build in PHP — it normally means hand-rolling a transactional outbox, consumer-side deduplication, and dead-lettering for every project. Freyr Message Broker packages that machinery as a reusable library: produce inside your database transaction, and the message reaches its transport with **at-least-once delivery + consumer-side deduplication** — exactly-once *processing* — with poison messages dead-lettered and replayable.

It runs over **AMQP (RabbitMQ)** so you adopt it within your existing infrastructure instead of standing up a new broker: competing-consumer queues, routed by message name. It gives no ordering guarantee: rows publish in any order, and consumers must be order-insensitive.

> **Status: 0.x.** No stable release yet; every API may change between minor versions.
> A minor release (0.**x**.0) holds a major change and can break code. A patch release
> (0.x.**y**) holds smaller improvements and fixes.

## Packages

The library has five packages. **Today, one Composer package
(`freyr/message-broker`) holds all five.** Separate Composer packages and
separate repositories are planned. Each package has its own folder of
documents, so that the documents move with the code.

| Package | Folder in this repo | What it holds | Documents |
|---|---|---|---|
| Core (`freyr/message-broker`) | `src/broker/` | The ports, the relay loop, the delivery processor, retry and dead letters, the wire format, the console commands and the test kit. | [docs/](docs/) |
| PostgreSQL adapter (`freyr/message-broker-storage-postgresql`) | `src/adapter/postgresql/` | The outbox, deduplication and dead-letter stores, the transaction, the schema installer and the outbox retention. | [storage/postgresql/docs/](storage/postgresql/docs/) |
| AMQP adapter (`freyr/message-broker-transport-amqp`) | `src/adapter/amqp/` | The AMQP publisher and consumer, the wait-queue retry and the config classes. | [transport/amqp/docs/](transport/amqp/docs/) |
| HTTP adapter (`freyr/message-broker-webhook-http`) | `src/adapter/http/` | The webhook sender: signed parallel `POST` requests (Standard Webhooks) with Symfony HttpClient, the response classifier and the private network check. | [webhook/http/docs/](webhook/http/docs/) |
| Redis adapter (`freyr/message-broker-ratelimit-redis`) | `src/adapter/redis/` | The rate limit store of the webhook lanes: atomic Lua scripts with a phpredis, Relay or Predis client. | [ratelimit/redis/docs/](ratelimit/redis/docs/) |

The manifest and the README of each future package are in `packages/<name>/`.

## Install

```bash
composer require freyr/message-broker
```

This installs the code of all five packages. It replaces `freyr/message-broker-storage-postgresql`, `freyr/message-broker-transport-amqp`, `freyr/message-broker-webhook-http` and `freyr/message-broker-ratelimit-redis`.

Requires PHP 8.4 or 8.5 (tested in CI on both), `ext-pdo_pgsql`, and PostgreSQL 14+. Install the pieces you use:

| Need | Add |
|---|---|
| AMQP transport | `composer require php-amqplib/php-amqplib` |
| HTTP webhook sender | `composer require symfony/http-client symfony/http-foundation` |
| Redis rate limit store | `ext-redis` or `composer require predis/predis`, and Redis 7.0+ or Valkey 7.2+ |
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
adding workers to the lane. By default the relay marks rows published and never deletes
them. The command `message-broker:outbox:maintain` drops daily partitions
after the retention period (default 7 days). A partition with unpublished rows is kept, and the command exits non-zero, unless `--drop-unpublished` is set — see [docs/RELAY.md](docs/RELAY.md) and
[storage/postgresql/docs/RETENTION.md](storage/postgresql/docs/RETENTION.md).

## Published rows: mark or delete

The PostgreSQL outbox store has one setting for published rows,
`PublishedRows`:

```php
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Storage\PostgreSql\PublishedRows;

new PostgreSqlOutboxStore($pdo);                          // PublishedRows::Mark, the default
new PostgreSqlOutboxStore($pdo, PublishedRows::Delete);   // delete each row after its publish
new PostgreSqlOutboxStore($pdo, PublishedRows::from('delete')); // from configuration
```

- `Mark` sets `published_at`, and the rows stay until their partition expires: a
  record of each published message, and a stable claim plan under a steady load.
- `Delete` removes each row in the claim transaction, after the broker confirm.
  It used less PostgreSQL CPU and half the WAL when the relay drained a backlog,
  and more CPU under a steady load (median of 4 runs).

Set it on the store of the relay; the producer can keep the default. The
measurements and the choice are in
[storage/postgresql/docs/STORAGE.md](storage/postgresql/docs/STORAGE.md#published-rows-mark-or-delete).

## Documentation

- [Quickstart](docs/QUICKSTART.md) — produce → relay → consume, end to end, and webhooks (uses all four packages)

Core (`docs/core/`):

- [Relay and lanes](docs/RELAY.md) — lanes, the relay loop, failure handling, backoff
- [Delivery processor and retry](docs/CONSUMER.md) — the exactly-once algorithm, the retry policy
- [Ports](docs/PORTS.md) — the ports and the classes that implement them
- [CLI Reference](docs/CLI_REFERENCE.md) — every shipped console command
- [DLQ Operations](docs/DLQ_OPERATIONS.md) — inspecting, replaying, and purging dead letters
- [Production](docs/PRODUCTION.md) — deployment, graceful shutdown, observability, scaling
- [Testing](docs/TESTING.md) — contract test cases for adapter authors, in-memory fakes for applications

PostgreSQL adapter (`docs/postgresql/`):

- [Storage](storage/postgresql/docs/STORAGE.md) — the tables, the schema setup, the classes and their wiring
- [Retention](storage/postgresql/docs/RETENTION.md) — outbox partitions, `outbox:maintain`, pg_partman
- [Connection pooling](storage/postgresql/docs/CONNECTION_POOLING.md) — pgbouncer
- [Webhook delivery storage](storage/postgresql/docs/WEBHOOKS.md) — `webhook_deliveries`, the delivery store, its retention, pg_partman, the alert query

AMQP adapter (`docs/amqp/`):

- [Transport](transport/amqp/docs/TRANSPORT.md) — routing, config classes, the dedicated channel, the publisher, the topology
- [Consumer](transport/amqp/docs/CONSUMER.md) — the consumer, the TTL wait queues, the dead-letter exchange
- [Dead letters](transport/amqp/docs/DEAD_LETTERS.md) — the import of a dead letter queue, the read of `x-death`, the permissions

HTTP adapter (`docs/http/`):

- [HTTP sender](webhook/http/docs/SENDER.md) — the request, the configuration, the results, the timeouts, the DNS allowance and its known limit, the connections
- [Signature](webhook/http/docs/SIGNATURE.md) — the `webhook-` headers, the secret, the verification by a receiver
- [Network rules](webhook/http/docs/NETWORK.md) — the private network rule, the proxy rule, the `https` rule, no redirects

Redis adapter (`docs/redis/`):

- [Redis rate limit store](ratelimit/redis/docs/RATE_LIMIT.md) — the client bridges, the key prefix and the hash tag, the server versions, `maxmemory-policy`, the script cache

## License

MIT
