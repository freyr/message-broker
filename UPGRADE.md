# Upgrade guide

## From 0.6.x to 0.7.0

This release has no backward-compatibility aliases. Change your `use` statements and your wiring code as shown below.
The database schema, the wire format and the behavior of the relay and of the consumer do not change.

### New layout

The code is in three folders. Each folder has its own namespace and its own PSR-4 root:

| Package | Folder | Namespace |
|---|---|---|
| core | `src/broker/` | `Freyr\MessageBroker\` |
| PostgreSQL adapter | `src/adapter/postgresql/` | `Freyr\MessageBroker\Adapter\PostgreSql\` |
| AMQP adapter | `src/adapter/amqp/` | `Freyr\MessageBroker\Adapter\Amqp\` |

The adapter namespaces are flat: a class is directly in the adapter namespace.
The namespace `Freyr\MessageBroker\Transport` does not exist any more.
The package is still one Composer package, `freyr/message-broker`. You do not change `composer.json`.

The core has no PDO class, no `PhpAmqpLib` class and no adapter class in its code.
An adapter uses the core and its own package only. It never uses the other adapter.

### Renamed, moved and removed classes

Each row is one public class of 0.6.0 that has a new name or a new namespace.
All new names are relative to `Freyr\MessageBroker\`.

| Old class (0.6.0) | New class |
|---|---|
| `Outbox\PdoOutboxStore` | `Adapter\PostgreSql\PostgreSqlOutboxStore` |
| `Outbox\OutboxPartitionManager` | `Adapter\PostgreSql\PostgreSqlOutboxRetention` |
| `Outbox\PartitionPurgeReport` | `Outbox\RetentionReport` |
| `Transport\PdoDeduplicationStore` | `Adapter\PostgreSql\PostgreSqlDeduplicationStore` |
| `DeadLetter\PdoDeadLetterStore` | `Adapter\PostgreSql\PostgreSqlDeadLetterStore` |
| `Transport\DeduplicationStore` | `Consumer\DeduplicationStore` |
| `Transport\IdleSleep` | `Outbox\IdleSleep` |
| `Transport\Amqp\AmqpConsumer` | `Adapter\Amqp\AmqpConsumer` |
| `Transport\Amqp\AmqpMessagePublisher` | `Adapter\Amqp\AmqpMessagePublisher` |
| `Transport\Amqp\AmqpPublishConfig` | `Adapter\Amqp\AmqpPublishConfig` |
| `Transport\Amqp\AmqpQueueConfig` | `Adapter\Amqp\AmqpQueueConfig` |
| `Transport\Amqp\AmqpRelay` (removed) | `Outbox\Relay` with `Adapter\Amqp\AmqpMessagePublisher` |
| `Transport\Amqp\AmqpRetryPolicy` | `Retry\ExponentialRetryPolicy` |
| `Storage\Platform` (removed) | none; each PostgreSQL class holds its own SQL |
| `Storage\PostgreSqlPlatform` (removed) | none; `Adapter\PostgreSql\PostgreSqlSchemaInstaller` holds the DDL |

All other public classes of 0.6.0 keep their name and namespace. This includes `Message`, `ErrorHandler`,
`Console\*`, `Consumer\CallableDispatcher`, `Consumer\IncomingMessage`, `Consumer\MessageDispatcher`,
`DeadLetter\DeadLetter`, `DeadLetter\DeadLetterStore`, `DeadLetter\ReplayService`, `Outbox\OutboxStore`,
`Outbox\OutboxProducer`, `Outbox\OutboxRecord`, `Outbox\ClaimOutcome`, `Retry\Backoff`, `Retry\RetryAction`,
`Retry\RetryDecision`, `Serializer\*`, `Time\EpochMillis` and `Observability\BrokerEvents`.

New classes of this release (they are not in 0.6.0), relative to `Freyr\MessageBroker\`:

| New class | Package |
|---|---|
| `Consumer\DeliveryProcessor`, `Consumer\Delivery`, `Consumer\DeliveryControl` | core |
| `Outbox\Relay`, `Outbox\OutboxPublisher`, `Outbox\OutboxRetention` | core |
| `Retry\RetryPolicy`, `Retry\ExponentialRetryPolicy` | core |
| `Storage\SchemaInstaller`, `Storage\Transaction` | core |
| `Adapter\PostgreSql\PostgreSqlSchemaInstaller`, `Adapter\PostgreSql\PdoTransaction` | PostgreSQL adapter |
| `Adapter\Amqp\AmqpDeliveryControl`, `Adapter\Amqp\WaitQueuePublisher` | AMQP adapter |

### Wiring: producer and outbox store

Before:

```php
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;

$outbox = new PdoOutboxStore($pdo, new PostgreSqlPlatform());
$producer = new OutboxProducer($outbox, new JsonWireFormat(), lane: 'orders');
```

After:

```php
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;

$outbox = new PostgreSqlOutboxStore($pdo);
$producer = new OutboxProducer($outbox, new JsonWireFormat(), lane: 'orders');
```

### Wiring: relay

Before:

```php
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;

$relay = new AmqpRelay($outbox, $channel, new AmqpPublishConfig(exchange: 'orders'), JsonWireFormat::CONTENT_TYPE, lane: 'orders', confirmTimeoutSec: 5);
$relay->run();
```

After:

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Adapter\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Outbox\Relay;

$relay = new Relay(
    $outbox,
    new AmqpMessagePublisher($channel, new AmqpPublishConfig(exchange: 'orders'), JsonWireFormat::CONTENT_TYPE, confirmTimeoutSec: 5),
    lane: 'orders',
);
$relay->run();
```

The other arguments (`lane`, `batchSize`, `backoff`, `errorHandler`, `idleSleepMs`, `logger`, `events`) keep their names and defaults.

### Wiring: consumer

Before:

```php
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;

$consumer = new AmqpConsumer(
    channel: $channel,
    queue: new AmqpQueueConfig('orders_q'),
    deserializer: new JsonDeserializer(),
    dispatcher: $dispatcher,
    pdo: $pdo,
    deduplication: new PdoDeduplicationStore($pdo, new PostgreSqlPlatform()),
    retryPolicy: new AmqpRetryPolicy(maxAttempts: 5, backoff: $backoff),
    deadLetters: new PdoDeadLetterStore($pdo, new PostgreSqlPlatform()),
    name: 'orders_consumer',
);
```

After:

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Adapter\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;

$consumer = new AmqpConsumer(
    channel: $channel,
    queue: new AmqpQueueConfig('orders_q'),
    processor: new DeliveryProcessor(
        deserializer: new JsonDeserializer(),
        dispatcher: $dispatcher,
        transaction: new PdoTransaction($pdo),
        deduplication: new PostgreSqlDeduplicationStore($pdo),
        retryPolicy: new ExponentialRetryPolicy(maxAttempts: 5, backoff: $backoff),
        deadLetters: new PostgreSqlDeadLetterStore($pdo),
        name: 'orders_consumer',
    ),
);
```

Use the same PDO connection for the transaction, the deduplication store and your handler.

### Wiring: schema setup and maintain commands

Before:

```php
use Freyr\MessageBroker\Console\OutboxMaintainCommand;
use Freyr\MessageBroker\Console\SetupSchemaCommand;
use Freyr\MessageBroker\Outbox\OutboxPartitionManager;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;

$application->add(new SetupSchemaCommand($pdo, new PostgreSqlPlatform()));
$application->add(new OutboxMaintainCommand(new OutboxPartitionManager($pdo)));
```

After:

```php
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlSchemaInstaller;
use Freyr\MessageBroker\Console\OutboxMaintainCommand;
use Freyr\MessageBroker\Console\SetupSchemaCommand;

$application->add(new SetupSchemaCommand(new PostgreSqlSchemaInstaller($pdo)));
$application->add(new OutboxMaintainCommand(new PostgreSqlOutboxRetention($pdo)));
```

The options, the output and the exit codes of both commands do not change.

### Changed constructor and method signatures

- `PostgreSqlOutboxStore`, `PostgreSqlDeduplicationStore` and `PostgreSqlDeadLetterStore` take no `Platform` argument:
  `new PostgreSqlOutboxStore($pdo)`, `new PostgreSqlDeduplicationStore($pdo, deleteChunkSize: ...)`,
  `new PostgreSqlDeadLetterStore($pdo)`.
- `PostgreSqlOutboxRetention::ensurePartitions($daysAhead = 3, $today = null)` is now
  `ensureCapacity($daysAhead, $today = null)`. `$daysAhead` has no default.
- `PostgreSqlOutboxRetention::purge()` takes `(retentionDays, dryRun, dropUnpublished, today)`.
  `$dropUnpublished` is now before `$today`. `$today` is a test parameter of the PostgreSQL class
  and is not part of the `OutboxRetention` port.
- `SetupSchemaCommand` takes a `SchemaInstaller` (was `PDO` and `Platform`).
- `OutboxMaintainCommand` takes an `OutboxRetention` (was the concrete partition manager).
- `Outbox\Relay` replaces `AmqpRelay`. It takes an `OutboxPublisher` in place of the channel, the publish
  config and the content type.
- `AmqpConsumer` takes `(AMQPChannel $channel, AmqpQueueConfig $queue, DeliveryProcessor $processor, int $confirmTimeoutSec = 5)`.
  Before, it took 13 parameters. Pass `deserializer`, `dispatcher`, `deduplication`, `retryPolicy`, `deadLetters`,
  `name`, `errorHandler`, `logger` and `events` to `DeliveryProcessor`. Give it `new PdoTransaction($pdo)` as
  `transaction` in place of the `pdo` parameter.
- `Retry\RetryPolicy::decide(int $attempt, Throwable $error, IncomingMessage $message)` takes the message as a
  third parameter. `ExponentialRetryPolicy` has the same constructor as the old `AmqpRetryPolicy`.
- The retry republish to the TTL wait queue moves from `AmqpConsumer` to `Adapter\Amqp\WaitQueuePublisher`.
  The behavior does not change.
