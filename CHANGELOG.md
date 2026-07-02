# Changelog

All notable changes to this project will be documented in this file.

The former Symfony Messenger bundle remains available as `v0.x` tags.

## [1.0.0] - TBD

### Added

- Core transactional outbox with per-lane total ordering
- Consumer-side deduplication with configurable dead-letter handling
- Dead-lettering with replay capability for failed messages
- AMQP transport with ordered (AmqpRelay) and competing (CompetingAmqpRelay) relay modes, TTL/DLX retry support
- CompetingAmqpRelay for order-insensitive workloads with parallel per-lane draining via FOR UPDATE SKIP LOCKED claims
- Kafka transport with strict per-key FIFO ordering and offset-after-commit semantics (ext-rdkafka)
- Support for MySQL and PostgreSQL database backends
- JSON and Avro wire formats with Confluent schema registry compatibility and PSR-6 schema caching
- Schema registration and compatibility governance tooling
- PSR-3 logging and BrokerEvents for operational observability
- Dead-letter queue and deduplication CLI with dry-run and force-execute guardrails
- Storage interfaces (OutboxStore, DeadLetterStore, DeduplicationStore) with PDO default implementations
