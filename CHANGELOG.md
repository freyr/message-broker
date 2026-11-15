# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed

- **Breaking:** `Transport\Amqp\AmqpRelay` is removed. The relay loop is now `Outbox\Relay` (no AMQP import) and takes an `Outbox\OutboxPublisher`. Old: `new AmqpRelay($outbox, $channel, $publishConfig, $contentType, lane: 'orders', confirmTimeoutSec: 5)`. New: `new Relay($outbox, new AmqpMessagePublisher($channel, $publishConfig, $contentType, confirmTimeoutSec: 5), lane: 'orders')`. The other arguments (`lane`, `batchSize`, `backoff`, `errorHandler`, `idleSleepMs`, `logger`, `events`) keep their names and defaults. The AMQP behavior is unchanged
- `Transport\Amqp\AmqpMessagePublisher` implements `OutboxPublisher`; its `maxBatchSize()` returns `PHP_INT_MAX`, so one claim is one batch and one confirm wait

### Added

- Port `Outbox\OutboxPublisher` (`publishBatch()` returns the IDs that the transport refused, `maxBatchSize()`): a transport adapter implements it. `Relay` publishes the fresh rows of a claim in chunks of `maxBatchSize()`; a failed call backs off the rows of that call only, and later chunks stay due

## [0.6.0] - 2026-11-15

### Changed

- **Breaking:** `message-broker:outbox:maintain` and `OutboxPartitionManager::purge()` no longer drop an expired partition that holds unpublished rows (`published_at IS NULL`). The partition is kept and reported, and the command exits with code 1; it is dropped on a later run once its rows are published. A table that the run detached and then found to hold unpublished rows, an expired detached table, and a partition with a pending detach are attached again, so the relay can publish their rows
- `PartitionPurgeReport` has a new `kept` list (partition name to unpublished row count), `unpublishedRowsKept()` and `hasKept()`

### Added

- Option `--drop-unpublished` of `message-broker:outbox:maintain` and parameter `$dropUnpublished` of `purge()`: the previous behavior, a hard age limit that drops the partition with its unpublished rows

## [0.5.0] - 2026-11-15

The standalone library (PostgreSQL and AMQP, no framework) has the versions 0.1.0 and 0.5.0; 0.5.0 holds all changes below. The versions 0.2.0 to 0.4.0 belong to the earlier Symfony Messenger bundle of the same package name; their tags point to that separate history.

### Added

- Core transactional outbox on a partitioned PostgreSQL table (PostgreSQL 14+), with lanes as named drains and no ordering guarantee
- Partition retention: the relay marks rows published (`published_at` audit) and never deletes; `message-broker:outbox:maintain` creates daily partitions ahead (no default partition) and drops expired ones with `DETACH PARTITION ... CONCURRENTLY`; the retention period is also the age limit of an unpublished row
- Consumer-side deduplication (insert-or-ignore keyed by message id and consumer) turning at-least-once delivery into exactly-once processing
- Dead-lettering with replay capability for failed messages
- AMQP transport with a single AmqpRelay (any number of workers drain one lane in parallel via FOR UPDATE SKIP LOCKED claims, default batch 500), TTL/DLX retry support, and consumer-side dedup integration
- Consumer failure handling: the retry republish is confirmed before the ack; a database failure stops the consumer with the real error and uses no retry attempt; the dedup insert is prepared once per connection; `message-broker:dedup:cleanup` deletes in chunks with an index on `created_at`
- Relay failure handling: a broker nack backs off only the nacked rows; a channel, connection or confirm-timeout failure backs off the failed rows and stops the worker (crash-only); a retried row is published alone, so a refused message fails alone; an unroutable message is dropped by design
- PostgreSQL storage backend
- JSON wire format (`JsonWireFormat`, `JsonDeserializer`), encoded at produce time
- PSR-3 logging, BrokerEvents instrumentation events, and an ErrorHandler hook for operational observability
- Dead-letter queue and deduplication CLI with dry-run and force-execute guardrails
- Storage interfaces (OutboxStore, DeadLetterStore, DeduplicationStore) with PDO default implementations
