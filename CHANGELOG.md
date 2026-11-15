# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed

- `composer.json` now has `replace` for `freyr/message-broker-postgresql` and `freyr/message-broker-amqp` (`self.version`): when the code moves to separate packages, this package will stand in for them, so no second copy of the code is installed. The `branch-alias` (`dev-main` as `1.0-dev`) is removed because no 1.0 line is planned; `dev-main` now has no alias and does not satisfy `^0.7`

## [0.7.1] - 2026-11-15

### Added

- Test kit in the core, namespace `Freyr\MessageBroker\Testing\` (`src/broker/Testing/`): the abstract contract test cases `OutboxStoreContractTestCase`, `DeduplicationStoreContractTestCase`, `DeadLetterStoreContractTestCase`, `TransactionContractTestCase` and `OutboxPublisherContractTestCase` for the authors of adapters, and the in-memory fakes `InMemoryOutboxStore`, `InMemoryDeduplicationStore`, `InMemoryDeadLetterStore`, `InMemoryTransaction` and `FakeOutboxPublisher` (and `RecordingDeliveryControl`). The test cases extend the PHPUnit `TestCase`, so `phpunit/phpunit` is now in `suggest`. `Adapter\PostgreSql\Testing\PostgreSqlTestSupport` is the PostgreSQL test set-up for the adapter tests. See `docs/core/TESTING.md`

## [0.7.0] - 2026-11-15

The code is split into a core and two adapters inside this package. `UPGRADE.md` has the full table of old and new class names.

### Changed

- **Breaking:** the code moves to three folders with separate namespaces and three PSR-4 roots: the core in `src/broker/` (`Freyr\MessageBroker\`), the PostgreSQL adapter in `src/adapter/postgresql/` (`Freyr\MessageBroker\Adapter\PostgreSql\`) and the AMQP adapter in `src/adapter/amqp/` (`Freyr\MessageBroker\Adapter\Amqp\`). The six PostgreSQL classes and the six AMQP classes change namespace; `Transport\DeduplicationStore` is now `Consumer\DeduplicationStore` and `Transport\IdleSleep` is now `Outbox\IdleSleep`. The namespace `Freyr\MessageBroker\Transport` no longer exists. There are no aliases. See `UPGRADE.md` for the full table of old and new class names and for before and after wiring examples
- **Breaking:** the SQL dialect seam `Storage\Platform` and `Storage\PostgreSqlPlatform` are removed. Each PostgreSQL class holds its own SQL, and the core talks to storage through ports only
- **Breaking:** renamed classes, no aliases (the final namespaces are in the entry on the folder move above): `Outbox\PdoOutboxStore` -> `Adapter\PostgreSql\PostgreSqlOutboxStore`, `Transport\PdoDeduplicationStore` -> `Adapter\PostgreSql\PostgreSqlDeduplicationStore`, `DeadLetter\PdoDeadLetterStore` -> `Adapter\PostgreSql\PostgreSqlDeadLetterStore`, `Outbox\OutboxPartitionManager` -> `Adapter\PostgreSql\PostgreSqlOutboxRetention`, `Outbox\PartitionPurgeReport` -> `Outbox\RetentionReport`. The three stores take no `Platform` argument any more: `new PostgreSqlOutboxStore($pdo)`, `new PostgreSqlDeduplicationStore($pdo, deleteChunkSize: ...)`, `new PostgreSqlDeadLetterStore($pdo)`
- **Breaking:** `Adapter\PostgreSql\PostgreSqlOutboxRetention::ensurePartitions($daysAhead = 3, $today = null)` is now `ensureCapacity($daysAhead, $today = null)` (no default for `$daysAhead`). `purge()` takes `(retentionDays, dryRun, dropUnpublished, today)`; `$dropUnpublished` moved before `$today`. `$today` is a test parameter of the PostgreSQL class and is not part of the port
- **Breaking:** `SetupSchemaCommand` takes a `SchemaInstaller` (was `PDO` and `Platform`). `OutboxMaintainCommand` takes an `OutboxRetention` (was the concrete partition manager). Options, output and exit codes do not change
- **Breaking:** `Transport\Amqp\AmqpRelay` is removed. The relay loop is now `Outbox\Relay` (no AMQP import) and takes an `Outbox\OutboxPublisher`. Old: `new AmqpRelay($outbox, $channel, $publishConfig, $contentType, lane: 'orders', confirmTimeoutSec: 5)`. New: `new Relay($outbox, new AmqpMessagePublisher($channel, $publishConfig, $contentType, confirmTimeoutSec: 5), lane: 'orders')`. The other arguments (`lane`, `batchSize`, `backoff`, `errorHandler`, `idleSleepMs`, `logger`, `events`) keep their names and defaults. The AMQP behavior is unchanged
- `Adapter\Amqp\AmqpMessagePublisher` implements `OutboxPublisher`; its `maxBatchSize()` returns `PHP_INT_MAX`, so one claim is one batch and one confirm wait
- **Breaking:** `AmqpConsumer` (now `Adapter\Amqp\AmqpConsumer`) is split into a transport-neutral `Consumer\DeliveryProcessor` (the exactly-once algorithm, no AMQP import) and a thin AMQP loop. The constructor of `AmqpConsumer` changes from 13 parameters to `(AMQPChannel $channel, AmqpQueueConfig $queue, DeliveryProcessor $processor, int $confirmTimeoutSec = 5)`. Pass `deserializer`, `dispatcher`, `deduplication`, `retryPolicy`, `deadLetters`, `name`, `errorHandler`, `logger` and `events` to `DeliveryProcessor`, and give it `new PdoTransaction($pdo)` as `transaction` in place of the `pdo` parameter
- **Breaking:** `Transport\Amqp\AmqpRetryPolicy` is renamed to `Retry\ExponentialRetryPolicy` (same constructor and behavior, no alias). It implements the new interface `Retry\RetryPolicy`, whose `decide(int $attempt, Throwable $error, IncomingMessage $message)` takes the message as a third parameter
- The retry republish to the TTL wait queue moves from `AmqpConsumer` to `Adapter\Amqp\WaitQueuePublisher`; the behavior is unchanged

### Added

- Port `Outbox\OutboxRetention` (`ensureCapacity()`, `purge()`) with the implementation `Adapter\PostgreSql\PostgreSqlOutboxRetention`
- Port `Storage\SchemaInstaller` (`sql()` for the DDL of a new database, `install()` to create what is missing) with the implementation `Adapter\PostgreSql\PostgreSqlSchemaInstaller`. One operation covers the tables and the first partitions
- Port `Outbox\OutboxPublisher` (`publishBatch()` returns the IDs that the transport refused, `maxBatchSize()`): a transport adapter implements it. `Relay` publishes the fresh rows of a claim in chunks of `maxBatchSize()`; a failed call backs off the rows of that call only, and later chunks stay due
- `Storage\Transaction` and `Adapter\PostgreSql\PdoTransaction`: the database transaction of one delivery
- `Consumer\Delivery` (a transport-neutral delivery, with `deliveryCount`), `Consumer\DeliveryControl` and `Adapter\Amqp\AmqpDeliveryControl`

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
