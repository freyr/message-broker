# Freyr Message Broker

Standalone PHP messaging library (PHP ≥ 8.4): transactional outbox (partitioned PostgreSQL table, no ordering guarantee), consumer-side deduplication and DLQ, an AMQP producer/consumer. No framework coupling, plain PDO on PostgreSQL.

**Core concept.** The aim is a *true* outbox/inbox **exactly-once** layer for the PHP community — at-least-once delivery + consumer-side dedup/DLQ yielding exactly-once *processing*, the semantics that normally demand bespoke per-project glue. This library ships the **AMQP transport only** (RabbitMQ) so teams adopt it within their own infrastructure rather than being forced onto one broker: non-FIFO competing-consumer queues, routed by message name. There is no ordering at all: one `AmqpRelay` class, and any number of identical workers drain one lane via `FOR UPDATE SKIP LOCKED` claims, so rows publish in any order and consumers must be order-insensitive. Consumer-side dedup absorbs the duplicates a worker crash can produce. The relay marks rows published (`published_at`) and never deletes; `message-broker:outbox:maintain` drops expired daily partitions with `DETACH PARTITION ... CONCURRENTLY` (PostgreSQL 14+, so there is no DEFAULT partition and partitions are created ahead; pg_partman may create them). An expired partition that still holds unpublished rows is kept by default (reported, and the command exits with FAILURE); `--drop-unpublished` drops it with those rows (the retention period then is a hard age limit). Scope is **PostgreSQL + AMQP only**: there is no ordering of any kind (no per-lane order, no per-key FIFO, no Kafka transport). Research + decisions live in the vault.

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

## Mutation testing

Infection measures the quality of the tests; pcov (in the Docker image) collects the coverage. `make infection` runs the full suite on all CPU cores (about 1 minute locally); reports go to `var/infection/` (`infection.html` lists each escaped mutant). The thresholds are in `infection.json5` (`minMsi`, `minCoveredMsi`): 94% (measured 95.7%). Raise them when the tests improve; never lower them.

CI runs Infection on the changed lines of `src/` on each pull request (part of `ci-gate`), and the full suite on `main`. A pull request whose changed lines score below the threshold fails: add or tighten the tests that the escaped mutants show.

Parallel workers are isolated. Infection sets `TEST_TOKEN` for each worker; `FunctionalTestCase` then uses the database `<name>_t<token>` (created on first use) and appends `_t<token>` to queue and exchange names. Pass every AMQP queue or exchange name of a functional test through `self::isolated()`.
