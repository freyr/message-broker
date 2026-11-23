# Relay and lanes

The relay moves rows from the outbox to a transport. The relay loop is in the
core class `Relay`. It sends the rows through the `OutboxPublisher` port and
reads them through the `OutboxStore` port. This document describes the loop,
its failure handling and its backoff. The AMQP publisher is in
[amqp/TRANSPORT.md](amqp/TRANSPORT.md). The outbox table is in
[postgresql/STORAGE.md](postgresql/STORAGE.md).

**There is no ordering guarantee.** Rows publish in any order, so consumers
must be order-insensitive.

## Lanes

A lane is a named drain of the outbox table, used to isolate workloads: a
slow or failing workload on one lane does not delay another lane. A lane name
has 1 to 64 characters; a message name has 1 to 255 characters. `OutboxRecord`
rejects an empty or too long value with an `InvalidArgumentException`. Scale
the throughput of a lane by adding workers to it, up to the limit of its
RabbitMQ queue (see [PRODUCTION.md](PRODUCTION.md), *Scaling*).

## The relay

There is one relay class, `Relay`, behind the `OutboxPublisher` port; the AMQP
transport implements the port with `AmqpMessagePublisher`. Any number of identical worker
processes drain **one** lane in parallel. Each worker claims a batch of
eligible rows via `FOR UPDATE SKIP LOCKED` inside a transaction owned by the
outbox store, publishes the batch through the port (the AMQP publisher waits for publisher confirms) while it holds the
claim, and marks the rows published (`published_at`); retention drops them by partition (see
[postgresql/RETENTION.md](postgresql/RETENTION.md)). The PostgreSQL store can delete the published rows in the
claim transaction instead: `new PostgreSqlOutboxStore($pdo, PublishedRows::Delete)` (see
[postgresql/STORAGE.md](postgresql/STORAGE.md#published-rows-mark-or-delete)). Workers never block each
other — concurrent claimers skip each other's locked rows.

**No ordering promise.** Rows publish in any order, across workers and past
rows that are backing off; the claim takes the oldest-due rows first as a
fairness bias, not a contract.
Consumers must be order-insensitive.

**Failure and crash semantics.**

- **Nack.** When the broker nacks a message, only that row backs off, at a
  delay from its own `attempts` count (see *Backoff* below). The confirmed rows of the batch are marked published, and the worker
  continues.
- **Channel, connection or confirm timeout.** The worker backs off the rows of
  the failed publish, commits, and then lets the error propagate: the worker
  stops (crash-only), and Kubernetes starts the container again with a new
  connection. A
  broken worker never keeps a lane in backoff.
- **A row that failed before is published alone.** A claimed row with
  `attempts > 0` gets its own publish and its own confirm wait. A message
  that the broker refuses (for example, a message larger than the broker
  limit, which closes the channel) then fails alone. The rows of the claim
  that the worker did not try yet stay due and unchanged, so they do not wait
  behind the refused row.
- **Log context of a row that fails alone.** A call that holds exactly one
  row with `attempts > 0` fails with the keys `message_id`, `message_name` and
  `attempts` in the log context, in addition to `lane`, `rows` and `ids`.
  `attempts` is the count after the failure (the stored value plus 1). The
  `ErrorHandler` gets the same keys when the publisher throws. When the broker
  nacks the row, only the logger gets them: a nack does not reach the
  `ErrorHandler`. A failed call of fresh rows (a batch) has none of the three
  keys, and the log message texts do not change.
- **A failed batch backs off each row of the call.** This includes a row that
  the broker confirmed before the failure, because the relay does not know
  which rows of the call the broker accepted. Consumer-side deduplication
  absorbs the repeat. The relay has no automatic rule that gives up a row: a
  row that the transport refuses on each try keeps its backoff until an
  operator acts. To stop a message that is too large before it enters the
  outbox, give `LaneLimits` to the producer (see
  [amqp/TRANSPORT.md](amqp/TRANSPORT.md), *Size limits at produce time*).

The relay has no dead-letter path. A worker that crashes after publishing but
before its claim commits leaves the rows unpublished; the next claim
republishes them, and consumer-side deduplication absorbs the duplicate. An unpublished row
stays in the outbox. Retention keeps its partition, even after the retention
period (default 7 days), until the row is published or abandoned; only
`--drop-unpublished` drops it otherwise.

## A row that the transport refuses

The relay has no automatic rule for a refused row. It backs off the row from
its `attempts`, publishes it alone in the next claim, and the worker stops on
a throw. No broker reply code identifies a permanent refusal, so the relay
never abandons a row. The operator decides.

Runbook:

1. Find the row. Signs: a worker that stops again and again with the same
   single ID in the log context (`message_id`, with the `attempts` that grow
   from one failure to the next), or a row with a high `attempts` in
   `message-broker:outbox:failing`.
2. Read the broker error. Examples: 406 with "message size" (the body is above
   `max_message_size`), 501 `frame_too_large` (the headers do not fit one
   frame), 404 (the exchange does not exist).
3. Fix a setup error (a 404, or a 406 of a declaration) and do not abandon the
   row. The row publishes after the fix.
4. Abandon the row only when the message itself can never pass:
   `message-broker:outbox:abandon <id> --reason "..."`. The relay then skips
   the row, and retention drops it with its partition.

An abandoned row is not published. To undo an abandon, run this SQL while
retention keeps the partition of the row:

```sql
UPDATE outbox_messages SET abandoned_at = NULL WHERE id = '<id>' AND published_at IS NULL;
```

No command undoes an abandon. After retention drops the partition, the
message is gone.

## Backoff

The relay delays a failed row with `Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000)`.
This is the default of the `backoff` argument. The multiplier of
`Backoff::exponential()` is `4.0` unless you pass another value. The store
adds 1 to `attempts` each time a row fails. The row becomes due again after
`min(initialDelayMs × multiplier^attempts, maxDelayMs)`, where `attempts` is the
count before the failure. With the defaults, the delays after the first, second, third and later
failures are:

| Failure of the row | Delay before the next try |
|---|---|
| 1 | 1 s |
| 2 | 4 s |
| 3 | 16 s |
| 4 | 64 s |
| 5 | 256 s |
| 6 and later | 300 s (the maximum) |

## Wiring

```php
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Adapter\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Adapter\Amqp\AmqpPublishConfig;

// Each worker owns its own PDO connection and its own dedicated AMQPChannel.
$worker = new Relay(
    outbox: new PostgreSqlOutboxStore($workerPdo), // or ($workerPdo, PublishedRows::Delete)
    publisher: new AmqpMessagePublisher(
        $workerChannel,
        new AmqpPublishConfig(exchange: 'orders'),
        JsonWireFormat::CONTENT_TYPE,
        confirmTimeoutSec: 5,
    ),
    lane: 'orders',
    batchSize: 500,
    backoff: Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000),
);
$worker->run();
```

`run()` takes an optional `Freyr\MessageBroker\Worker\WorkerLimits`:
`$worker->run(new WorkerLimits(maxMessages: 10_000, maxSeconds: 3_600, maxMemoryBytes: 134_217_728))`.
The relay checks the limits at the end of each pass, so it can publish up to one batch
above `maxMessages`. When a limit is reached, the relay logs one `info` line
("Worker limit reached; the worker stops") with the reason, the counts, the elapsed
seconds and the memory in bytes, and then stops like it does on SIGTERM: `run()`
returns and the claims are already closed. `maxFailures` has no effect on the relay,
because a publisher failure already ends the run. The operator sets the limits with
the options of `message-broker:relay:run`; see [CLI_REFERENCE.md](CLI_REFERENCE.md).

`Relay` constructor arguments: `outbox`, `publisher`, `lane = 'default'`,
`batchSize = 500`, `backoff`, `errorHandler`, `idleSleepMs = 200`, `logger`,
`events`. The `AmqpMessagePublisher` arguments are in
[amqp/TRANSPORT.md](amqp/TRANSPORT.md).

The relay publishes the fresh rows of a claim in chunks of
`OutboxPublisher::maxBatchSize()`; each chunk is one `publishBatch()` call.
A failed call backs off the rows of that call only; the later
chunks stay due.

Run N of these as separate processes, each with its own connection and
channel, all pointed at the same lane name.

## Operational signal

Watch the age of the oldest unpublished row that is not abandoned
(`published_at IS NULL AND abandoned_at IS NULL`) per lane. A single stuck row does not stall a lane, because other rows overtake
it, so row age is the signal for a slowly draining lane. Also watch the exit
code of `message-broker:outbox:maintain`, which is not 0 when it kept a
partition because of unpublished rows.
