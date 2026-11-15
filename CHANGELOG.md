# Changelog

All notable changes to this project will be documented in this file.

## [0.1.0] - 2026-11-15

### Added

- Core transactional outbox on a partitioned PostgreSQL table (PostgreSQL 14+), with lanes as named drains and no ordering guarantee
- Partition retention: the relay marks rows published (`published_at` audit) and never deletes; `message-broker:outbox:maintain` creates daily partitions ahead (no default partition) and drops expired ones with `DETACH PARTITION ... CONCURRENTLY`; the retention period is also the age limit of an unpublished row
- Consumer-side deduplication (insert-or-ignore keyed by message id and consumer) turning at-least-once delivery into exactly-once processing
- Dead-lettering with replay capability for failed messages
- AMQP transport with a single AmqpRelay (any number of workers drain one lane in parallel via FOR UPDATE SKIP LOCKED claims, default batch 500), TTL/DLX retry support, and consumer-side dedup integration
- Consumer failure handling: the retry republish is confirmed before the ack; a database failure stops the consumer with the real error and uses no retry attempt; the dedup insert is prepared once per connection; `message-broker:dedup:cleanup` deletes in chunks with an index on `created_at`
- Relay failure handling: a broker nack backs off only the nacked rows; a channel, connection or confirm-timeout failure backs off the failed rows and stops the worker (crash-only); a retried row is published alone, so a refused message fails alone; an unroutable message is dropped by design
- PostgreSQL storage backend
- JSON and Avro wire formats; Avro uses registry-free committed-schema governance (derived ids + reader/writer resolution)
- Schema validation tooling (`schema:validate`) and an offline CI compatibility gate
- PSR-3 logging, BrokerEvents instrumentation events, and an ErrorHandler hook for operational observability
- Dead-letter queue and deduplication CLI with dry-run and force-execute guardrails
- Storage interfaces (OutboxStore, DeadLetterStore, DeduplicationStore) with PDO default implementations
