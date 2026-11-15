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

**An unroutable message is dropped by design.** The relay publishes without
the `mandatory` flag. When no queue binding matches the message name, the
broker drops the message and confirms it, and the relay marks the row
published. A message that nobody subscribes to is not a failure. Bind the
queues before the producers send messages that must arrive.

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

The consumer puts its channel in publisher-confirm mode at the first retry. It
acks the original delivery only after the broker confirms the republish. A
nack or a confirm timeout stops the process, and the original delivery is
redelivered with the same attempt.

A database failure is not a handler failure. When `BEGIN` fails, or the
rollback fails on a dead connection, the consumer reports the error and stops.
The delivery is redelivered, and no retry attempt is used.

**Do not catch a database error in a handler and continue.** The handler runs
inside the consumer transaction. In PostgreSQL, one failed statement aborts
the whole transaction. A `COMMIT` of an aborted transaction does a `ROLLBACK`
and returns no error, so `PDO::commit()` returns `true`. When the handler
catches the exception and returns, the consumer commits and acks the message,
but the handler work and the dedup row are not in the database. The message
is lost, and nothing is logged. The consumer does not detect this. Let
database errors propagate; the consumer then rolls back and uses the retry
path. For a statement that is allowed to fail, use `INSERT ... ON CONFLICT`,
or run it in a savepoint (for example a nested Doctrine DBAL transaction).

**Set a dead-letter exchange on the work queue.** The consumer stops, and
RabbitMQ redelivers, when a message cannot be decoded for a temporary reason
or when the database fails. A message that always causes this, or a handler
that ends the PHP process (for example, out of memory), comes back again and
again. A quorum queue drops such a message after its delivery limit (20 by
default in RabbitMQ 4) when the queue has no dead-letter exchange. A classic
queue redelivers it without a limit. Declare the work queue with
`x-dead-letter-exchange` (and, for a quorum queue, a `x-delivery-limit` that
you choose), and monitor the dead-letter queue.

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

**Failure and crash semantics.**

- **Nack.** When the broker nacks a message, only that row backs off, at a
  delay from its own `attempts` count (exponential, 1 s doubling, maximum
  300 s). The confirmed rows of the batch are marked published, and the worker
  continues.
- **Channel, connection or confirm timeout.** The worker backs off the rows of
  the failed publish, commits, and then lets the error propagate: the worker
  stops (crash-only), and the supervisor restarts it with a new connection. A
  broken worker never keeps a lane in backoff.
- **A row that failed before is published alone.** A claimed row with
  `attempts > 0` gets its own publish and its own confirm wait. A message
  that the broker refuses (for example, a message larger than the broker
  limit, which closes the channel) then fails alone. The rows of the claim
  that the worker did not try yet stay due and unchanged, so they do not wait
  behind the refused row.

The relay has no dead-letter path. A worker that crashes after publishing but
before its claim commits leaves the rows unpublished; the next claim
republishes them, and consumer-side deduplication absorbs the duplicate. An unpublished row
stays until it passes the retention period (default 7 days); then retention
drops it with its partition.

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
    batchSize: 500,
    backoff: Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000),
);
$worker->run();
```

Constructor arguments: `outbox`, `amqp`, `publish`, `contentType`,
`lane = 'default'`, `batchSize = 500`, `backoff`, `errorHandler`,
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
the throughput of a lane by adding workers to it, up to the limit of its
RabbitMQ queue (see [PRODUCTION.md](PRODUCTION.md), *Scaling*).

### Outbox table and retention

`outbox_messages` is a PostgreSQL table partitioned by range on `created_at`
(PostgreSQL 14+ only). It has one partition per UTC day, named
`outbox_messages_pYYYYMMDD`, and no default partition: an insert for a day
with no partition fails. Columns: `id` UUID, `lane`
VARCHAR(64), `message_name` VARCHAR(255), `body` BYTEA (the final wire bytes,
payload only), `headers` JSONB (the produce-time headers), `created_at`
TIMESTAMP(3), `available_at` TIMESTAMP(3), `attempts` INTEGER and
`published_at` TIMESTAMP(3) NULL. The primary key is `(id, created_at)`. A
partial index on `(lane, available_at)` where `published_at IS NULL` serves
the claim query.

The relay only sets `published_at`. Rows leave the table when
`message-broker:outbox:maintain` drops an expired daily partition, with any
unpublished rows that are still in it. See
[CLI_REFERENCE.md](CLI_REFERENCE.md) and [PRODUCTION.md](PRODUCTION.md).

### Operational signal

Watch the age of the oldest unpublished row (`published_at IS NULL`) per
lane. A single stuck row does not stall a lane, because other rows overtake
it, so row age is the signal for a slowly draining lane. Also watch the exit
code of `message-broker:outbox:maintain` and its warnings about dropped
unpublished rows.
