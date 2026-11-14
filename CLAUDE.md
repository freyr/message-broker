# Freyr Message Broker

Standalone PHP messaging library (PHP ≥ 8.4): transactional outbox with total per-lane ordering, consumer-side deduplication and DLQ, an AMQP producer/consumer. No framework coupling, plain PDO on PostgreSQL.

**Core concept.** The aim is a *true* outbox/inbox **exactly-once** layer for the PHP community — at-least-once delivery + consumer-side dedup/DLQ yielding exactly-once *processing*, the semantics that normally demand bespoke per-project glue. This library ships the **AMQP transport only** (RabbitMQ) so teams adopt it within their own infrastructure rather than being forced onto one broker: non-FIFO competing-consumer queues, routed by message name. Total per-lane ordering is the ordered default; the AMQP `CompetingAmqpRelay` is the per-relay opt-out for order-insensitive workloads — N workers drain one lane via `FOR UPDATE SKIP LOCKED` claims, and consumer-side dedup absorbs the duplicates a worker crash can produce. Scope is **PostgreSQL + AMQP only**: there is no per-key FIFO (no consistent-hash lane mode, no resequencer, no Kafka transport) — per-lane order of the ordered relay is the only ordering guarantee. Research + decisions live in the vault.

Wire format is a global setup-time choice: `setup:schema --format=json|avro`. Encoding happens at **produce time** (the outbox `body` column holds the final wire bytes, payload only; the envelope lives in a `metadata` column); the PHP relay is a pure byte-pump that explodes `metadata` into individual `x-message-*` transport headers. A non-conforming payload fails at produce (the encode *is* the poison check). Avro is **registry-free**: schema ids are derived from committed `.avsc` files (immutable per-version layout `schemas/<subject>/v<N>.avsc`) and resolved by `FileSchemaRegistry`; the consumer does reader/writer resolution (older writer → newer reader, reader defaults filled). Validate the committed set in CI with `message-broker:schema:validate` (unique derived ids + producer-superset); enforce BC/FC with the offline Confluent maven gate (`make schema-compat`), never a live registry.

Design spec and research notes live in the vault: `~/Vaults/Freyr/message-broker/`.

## Connection pooling

The ordered relay (`AmqpRelay`) holds a **session-scoped** advisory lock (`pg_try_advisory_lock`) for its whole lifetime, so its database connection MUST be **direct or a session-mode pool — never a transaction-mode pooler** (e.g. pgbouncer `pool_mode = transaction`), which silently breaks the one-relay-per-lane ordering guarantee. The producer (HTTP hot path) holds no session state and is safe on any pool mode; the consumer and `CompetingAmqpRelay` use only transaction-scoped locks. See `docs/PRODUCTION.md` → *Connection pooling (pgbouncer)*.

## After every code change

Run quality gates and tests through Docker (never host PHP):

```
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=-1
docker compose run --rm php vendor/bin/ecs check
```

Functional tests need PostgreSQL + RabbitMQ — both in compose; Avro tests use committed `.avsc` fixtures (no registry).
