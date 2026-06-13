# Freyr Message Broker

Standalone PHP messaging library (PHP ≥ 8.4): transactional outbox with total per-lane ordering, consumer-side deduplication and DLQ, per-transport producers/consumers (AMQP first; Kafka, SQS planned). No framework coupling, plain PDO (MySQL, PostgreSQL).

Wire format is a global setup-time choice: `setup:schema --format=json|avro`. Encoding happens at **produce time** (the outbox `body` column holds the final wire bytes, payload only; the envelope lives in a `metadata` column); the PHP relay is a pure byte-pump that explodes `metadata` into individual `x-message-*` transport headers. Register Avro schemas out-of-band with `message-broker:schema:register` (driven by the same `FileSchemaStore` map the producer uses); govern per-subject compatibility with `message-broker:schema:compatibility`. Schema ids/schemas are cached behind a PSR-6 pool (Array/File/Redis). A non-conforming payload fails at produce (the encode *is* the poison check). The Debezium CDC relay is an optional spike behind the `debezium` compose profile (`docker/debezium/`).

Design spec and research notes live in the vault: `~/Documents/Vaults/Freyr/message-broker/docs/`.

## After every code change

Run quality gates and tests through Docker (never host PHP):

```
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=-1
docker compose run --rm php vendor/bin/ecs check
```

Functional tests need MySQL + RabbitMQ + Confluent Schema Registry (backed by a single-node Kafka) + Redis — all in compose; Avro tests register schemas out-of-band in `setUpBeforeClass()`.
