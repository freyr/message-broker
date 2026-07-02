# Transports

The library ships two transports — AMQP (RabbitMQ) and Kafka (`ext-rdkafka`)
— each met on its own terms. There is no shared relay/consumer interface:
each transport has its own concrete relay class(es), consumer class, and
config vocabulary.

**Strict per-key FIFO is a Kafka capability. AMQP routes by message name and
does not offer strict FIFO** — best-effort per-key ordering on AMQP
(consistent-hash routing + a single active consumer) was researched and is
**postponed**; its producer-side routing-key seam was removed so the current
API does not imply a capability that isn't built. If your workload needs
strict per-key ordering, produce to Kafka.

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
every competing-relay worker) must own its `AMQPChannel` exclusively — one
relay process, one dedicated channel.

### Two relay modes

**Ordered (default): `AmqpRelay`.** Exactly one relay owns a lane via a
connection-scoped advisory lock (`tryAcquireLane`/`releaseLane`); it drains
the lane's contiguous prefix in id order, publishes the whole eligible batch,
waits for one confirm covering the batch, then deletes — total in-order
publishing per lane. A second relay started on the same lane stands by,
retrying `tryAcquireLane` on every idle tick, and takes over the instant the
active relay releases (clean shutdown or crash — the lock is session-scoped
and self-releases when the connection dies).

```php
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;

$outbox = new PdoOutboxStore($pdo, new MySqlPlatform());

$relay = new AmqpRelay(
    outbox: $outbox,
    amqp: $channel,                                   // dedicated AMQPChannel
    publish: new AmqpPublishConfig(exchange: 'orders'),
    contentType: JsonWireFormat::CONTENT_TYPE,
    lane: 'orders',
    batchSize: 100,
);
$relay->run();
```

**Competing (opt-in): `CompetingAmqpRelay`.** For order-insensitive
workloads: N identical worker processes drain **one** lane in parallel. Each
worker claims a batch of eligible rows via `FOR UPDATE SKIP LOCKED` inside a
transaction owned by the outbox store, publishes while holding the claim,
and deletes on success. Workers never block each other — concurrent claimers
skip each other's locked rows — and a crashed worker's claimed rows are
instantly reclaimable, because its locks die with its connection.

**No ordering promise.** Rows overtake freely, both across workers and past
rows that are backing off; id order (UUIDv7) is a FIFO bias, not a contract.
Consumers of a competing lane must be order-insensitive. A publish failure
is batch-granular: nothing is deleted, and every claimed row backs off at its
own attempts-derived delay — a crashed or failing worker can cause its
in-flight batch to republish, which consumer-side deduplication absorbs.
This mode is AMQP-only; Kafka keeps the ordered drain.

```php
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\CompetingAmqpRelay;

// Each worker owns its own PDO connection and its own dedicated AMQPChannel.
$worker = new CompetingAmqpRelay(
    outbox: new PdoOutboxStore($workerPdo, new MySqlPlatform()),
    amqp: $workerChannel,
    publish: new AmqpPublishConfig(exchange: 'orders'),
    contentType: JsonWireFormat::CONTENT_TYPE,
    lane: 'orders',
    batchSize: 100,
    backoff: Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000),
);
$worker->run();
```

Run N of these as separate processes, each with its own connection and
channel, all pointed at the same lane name.

### Lane sharding

Since one lane has exactly one active *ordered* relay, lane sharding is how
you scale ordered throughput: fan production across `lane-1..N` (e.g. a hash
of the message key) and run one ordered relay per lane. This parallelizes
across lanes while keeping total order within each one.

### Operational signal per mode

- **Ordered mode** — a backing-off lane head blocks everything behind it, so
  watch for a blocked lane (the oldest unrelayed row growing stale).
- **Competing mode** — a blocked head cannot block the lane (rows overtake
  it), so a single stuck row will not show up as a stall. Watch **outbox row
  age** instead — the oldest `available_at`/`created_at` across the lane.

## Kafka

The Kafka relay and consumer need `ext-rdkafka`. The relay forces an
**idempotent producer** with the `murmur2_random` partitioner (matching
Debezium's Java-producer default): `message_key → partition` is stable, so
order survives broker retries — this is what gives Kafka **strict per-key
FIFO**.

```php
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Transport\Kafka\KafkaPublishConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaRelay;

$relay = new KafkaRelay(
    outbox: new PdoOutboxStore($pdo, new MySqlPlatform()),
    publish: new KafkaPublishConfig(brokers: 'kafka-broker-1:9092', topic: 'orders'),
    lane: 'orders',
);
$relay->run();
```

`KafkaPublishConfig(string $brokers, string $topic)` — both must be
non-empty. Like the AMQP relays, `KafkaRelay` drains one lane to one topic,
batches the eligible prefix, and confirms the whole batch with one `flush()`
before deleting — there is no competing Kafka relay; the drain is always
ordered.

The consumer commits its offset manually, **only after** the dedup/dispatch
database transaction commits — a crash between the two redelivers the
message, and consumer-side dedup absorbs it (at-least-once + dedup =
exactly-once processing). Retry is **in process**: on a transient dispatch
failure the consumer sleeps and retries the same message before advancing,
so the partition stays blocked for the duration — this is what preserves
per-key FIFO through a retry.

```php
use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Transport\Kafka\KafkaConsumer;
use Freyr\MessageBroker\Transport\Kafka\KafkaConsumerConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;

$consumer = new KafkaConsumer(
    config: new KafkaConsumerConfig(brokers: 'kafka-broker-1:9092', topic: 'orders', groupId: 'orders_consumer'),
    deserializer: new JsonDeserializer(),
    dispatcher: new CallableDispatcher(static function (IncomingMessage $m): void {
        // your handler
    }),
    pdo: $pdo,
    deduplication: new PdoDeduplicationStore($pdo, new MySqlPlatform()),
    retryPolicy: new KafkaRetryPolicy(maxAttempts: 5, backoff: Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000)),
    deadLetters: new PdoDeadLetterStore($pdo, new MySqlPlatform()),
    name: 'orders_consumer',
);
$consumer->run();
```

`KafkaConsumerConfig(string $brokers, string $topic, string $groupId, string $autoOffsetReset = 'earliest')`
— `brokers`, `topic`, and `groupId` must be non-empty; `autoOffsetReset` must
be `'earliest'` or `'latest'`. Offset commit is always manual
(`enable.auto.commit=false`) — there is no configuration knob to change that,
because manual, post-commit offset advance is what makes exactly-once
processing hold.

`KafkaRetryPolicy(int $maxAttempts = 5, ?Backoff $backoff = null)` — same
decision shape as `AmqpRetryPolicy` (`retry` while under budget, otherwise
`deadLetter`); the delay is enacted by sleeping in process rather than by a
transport-native wait queue.
