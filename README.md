# Freyr Message Broker

Standalone PHP messaging library: transactional outbox with total per-lane ordering, consumer-side deduplication and dead-lettering with replay, and per-transport producers and consumers — AMQP first, Kafka and SQS planned. Plain PDO storage (MySQL, PostgreSQL), no framework coupling.

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
