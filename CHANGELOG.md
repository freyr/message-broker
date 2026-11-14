# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- Core transactional outbox with per-lane total ordering
- Consumer-side deduplication (insert-or-ignore keyed by message id and consumer) turning at-least-once delivery into exactly-once processing
- Dead-lettering with replay capability for failed messages
- AMQP transport with ordered relay mode (AmqpRelay), TTL/DLX retry support, and consumer-side dedup integration
- CompetingAmqpRelay for order-insensitive workloads with parallel per-lane draining via FOR UPDATE SKIP LOCKED claims
- PostgreSQL storage backend
- JSON and Avro wire formats; Avro uses registry-free committed-schema governance (derived ids + reader/writer resolution)
- Schema validation tooling (`schema:validate`) and an offline CI compatibility gate
- PSR-3 logging, BrokerEvents instrumentation events, and an ErrorHandler hook for operational observability
- Dead-letter queue and deduplication CLI with dry-run and force-execute guardrails
- Storage interfaces (OutboxStore, DeadLetterStore, DeduplicationStore) with PDO default implementations
