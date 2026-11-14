# Transports

This library ships the **AMQP transport only** (RabbitMQ), met on its own
terms. AMQP has its own concrete relay class, consumer class, and config
vocabulary.

**AMQP routes by message name and gives no ordering guarantee.** Rows publish
in any order, so consumers must be order-insensitive.

## AMQP (RabbitMQ)

The AMQP relay routes by **message name**: it publishes to a named exchange
using the message's `name` (e.g. `order.placed`) as the routing key. There is
no per-key routing knob.

### Config classes

```php
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;

$publish = new AmqpPublishConfig(exchange: 'orders', publisherConfirms: true);
$queue = new AmqpQueueConfig(queue: 'orders_q', prefetch: 32);
```

- `AmqpPublishConfig(string $exchange, bool $publisherConfirms = true)` — the
  exchange must be non-empty (the default `''` exchange is not a supported
  target); `publisherConfirms` enables `confirm_select()` + one confirm wait
  per published batch.
- `AmqpQueueConfig(string $queue, int $prefetch = 32)` — the queue must be
  non-empty; `prefetch` (0 = unlimited) sets `basic_qos` for the consumer.

Consumer-side retry uses a durable per-delay wait queue with a
`x-message-ttl` that dead-letters back to the work queue through the default
exchange (transport-native delay), rather than an in-process sleep:

```php
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;

$retryPolicy = new AmqpRetryPolicy(
    maxAttempts: 5,
    backoff: Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000),
);
```

`AmqpRetryPolicy::decide()` returns `retry` (republish to the TTL wait queue,
`x-attempt` incremented) while `$attempt < maxAttempts`, otherwise
`deadLetter`.

### Dedicated channel requirement

Publisher-confirm mode is **channel-global state**: any publisher sharing a
relay's channel corrupts its confirm bookkeeping. Every relay process (and
every relay worker) must own its `AMQPChannel` exclusively — one
relay process, one dedicated channel.

### The relay

There is one relay class, `AmqpRelay`. Any number of identical worker
processes drain **one** lane in parallel. Each worker claims a batch of
eligible rows via `FOR UPDATE SKIP LOCKED` inside a transaction owned by the
outbox store, publishes the batch with publisher confirms while it holds the
claim, and marks the rows published (`published_at`). The relay never deletes
rows; retention drops them by partition (see below). Workers never block each
other — concurrent claimers skip each other's locked rows.

**No ordering promise.** Rows publish in any order, across workers and past
rows that are backing off; the claim takes the oldest-due rows first as a
fairness bias, not a contract.
Consumers must be order-insensitive.

**Failure and crash semantics.** A publish failure is batch-granular:
nothing is marked published, and every claimed row backs off at its own
attempts-derived delay (exponential, 1 s ×4, maximum 300 s). The relay has
no dead-letter path. A worker that crashes after publishing but before its
claim commits leaves the rows unpublished; the next claim republishes them,
and consumer-side deduplication absorbs the duplicate. A row is never removed
while it is unpublished, so there is no data-loss path.

```php
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;

// Each worker owns its own PDO connection and its own dedicated AMQPChannel.
$worker = new AmqpRelay(
    outbox: new PdoOutboxStore($workerPdo, new PostgreSqlPlatform()),
    amqp: $workerChannel,
    publish: new AmqpPublishConfig(exchange: 'orders'),
    contentType: JsonWireFormat::CONTENT_TYPE,
    lane: 'orders',
    batchSize: 100,
    backoff: Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000),
);
$worker->run();
```

Constructor arguments: `outbox`, `amqp`, `publish`, `contentType`,
`lane = 'default'`, `batchSize = 100`, `backoff`, `errorHandler`,
`idleSleepMs = 200`, `confirmTimeoutSec = 5`, `logger`, `events`.

Run N of these as separate processes, each with its own connection and
channel, all pointed at the same lane name.

The relay builds the `x-message-id`, `x-message-name` and `x-created-at` AMQP
headers from the `id`, `message_name` and `created_at` columns of the row.

### Lanes

A lane is a named drain of the outbox table, used to isolate workloads: a
slow or failing workload on one lane does not delay another lane. A lane name
has 1 to 64 characters; a message name has 1 to 255 characters. `OutboxRecord`
rejects an empty or too long value with an `InvalidArgumentException`. Scale
the throughput of a lane by adding workers to it.

### Outbox table and retention

`outbox_messages` is a PostgreSQL table partitioned by range on `created_at`
(PostgreSQL 13+ only). It has one partition per UTC day, named
`outbox_messages_pYYYYMMDD`, and a `outbox_messages_default` partition that
catches rows when no daily partition exists. Columns: `id` UUID, `lane`
VARCHAR(64), `message_name` VARCHAR(255), `body` BYTEA (the final wire bytes,
payload only), `headers` JSONB (the produce-time headers), `created_at`
TIMESTAMP(3), `available_at` TIMESTAMP(3), `attempts` INTEGER and
`published_at` TIMESTAMP(3) NULL. The primary key is `(id, created_at)`. A
partial index on `(lane, available_at)` where `published_at IS NULL` serves
the claim query.

The relay only sets `published_at`. Rows leave the table when
`message-broker:outbox:maintain` drops an expired daily partition that holds
only published rows, or deletes expired published rows from the DEFAULT
partition. See
[CLI_REFERENCE.md](CLI_REFERENCE.md) and [PRODUCTION.md](PRODUCTION.md).

### Operational signal

Watch the age of the oldest unpublished row (`published_at IS NULL`) per
lane. A single stuck row does not stall a lane, because other rows overtake
it, so row age is the signal for a slowly draining lane. Also watch the exit
code of `message-broker:outbox:maintain` and the rows that remain in the
default partition.
