# Freyr Message Broker

Standalone PHP messaging library (PHP ≥ 8.4): transactional outbox with total per-lane ordering, consumer-side deduplication and DLQ, per-transport producers/consumers (AMQP first; Kafka, SQS planned). No framework coupling, plain PDO (MySQL, PostgreSQL).

Design spec and research notes live in the vault: `~/Documents/Vaults/Freyr/message-broker/docs/`.

## After every code change

Run quality gates and tests through Docker (never host PHP):

```
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=-1
docker compose run --rm php vendor/bin/ecs check
```
