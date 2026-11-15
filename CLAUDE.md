# Freyr Message Broker

Standalone PHP messaging library (PHP ≥ 8.4): transactional outbox (partitioned PostgreSQL table, no ordering guarantee), consumer-side deduplication and DLQ, an AMQP producer/consumer. No framework coupling, plain PDO on PostgreSQL.

**Core concept.** The aim is a *true* outbox/inbox **exactly-once** layer for the PHP community — at-least-once delivery + consumer-side dedup/DLQ yielding exactly-once *processing*, the semantics that normally demand bespoke per-project glue. This library ships the **AMQP transport only** (RabbitMQ) so teams adopt it within their own infrastructure rather than being forced onto one broker: non-FIFO competing-consumer queues, routed by message name. There is no ordering at all: one `AmqpRelay` class, and any number of identical workers drain one lane via `FOR UPDATE SKIP LOCKED` claims, so rows publish in any order and consumers must be order-insensitive. Consumer-side dedup absorbs the duplicates a worker crash can produce. The relay marks rows published (`published_at`) and never deletes; `message-broker:outbox:maintain` drops daily partitions after the retention period with `DETACH PARTITION ... CONCURRENTLY` (PostgreSQL 14+, so there is no DEFAULT partition and partitions are created ahead; pg_partman may create them). The retention period (default 7 days) is also the age limit of an unpublished row: an expired partition is dropped with its unpublished rows. Scope is **PostgreSQL + AMQP only**: there is no ordering of any kind (no per-lane order, no per-key FIFO, no Kafka transport). Research + decisions live in the vault.

Messages are JSON (`JsonWireFormat`, `JsonDeserializer`). Encoding happens at **produce time** (the outbox `body` BYTEA column holds the final wire bytes, payload only; produce-time headers live in a `headers` JSONB column); the PHP relay is a pure byte-pump that builds the `x-message-id`, `x-message-name` and `x-created-at` transport headers from the `id`, `message_name` and `created_at` columns. A payload that cannot be encoded fails at produce. There is no Avro and no schema registry.

Design spec and research notes live in the vault: `~/Vaults/Freyr/message-broker/`.

## Connection pooling

No component holds a session-scoped lock. The relay and the consumer use only transaction-scoped locks, so every pool mode works, including a transaction-mode pooler. See `docs/PRODUCTION.md` → *Connection pooling*.

## After every code change

Run quality gates and tests through Docker (never host PHP):

```
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=-1
docker compose run --rm php vendor/bin/ecs check
```

Functional tests need PostgreSQL + RabbitMQ — both in compose.
