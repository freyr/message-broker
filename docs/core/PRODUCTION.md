# Production

Operational guidance for running relays and consumers in production: the
deployment model, graceful shutdown, observability and scaling. Outbox
retention and connection pooling are in the documents of the PostgreSQL
adapter: [../postgresql/RETENTION.md](../postgresql/RETENTION.md) and
[../postgresql/CONNECTION_POOLING.md](../postgresql/CONNECTION_POOLING.md).

## Deployment model — crash-only on Kubernetes

The library is made for Kubernetes. Each relay worker, consumer and webhook
delivery worker is one process, the only process of its container. Run each
kind of worker as a `Deployment`: the relay workers of one lane, the
consumers of one queue, the webhook delivery workers. Kubernetes starts a
container again after each exit, and HPA or KEDA changes the number of pods.
The library does not fork, has no worker pool and does not watch other
processes.

On any broker or database failure, the process **crashes by design** — there
is no in-process reconnect logic; Kubernetes starts the container again.

This is safe because:

- **Claims are transaction-scoped and release on connection close.** A
  relay worker claims rows with `FOR UPDATE SKIP LOCKED` inside one
  transaction. If the worker crashes, the connection closes, the transaction
  rolls back, and another worker claims the rows at once.
- **`publish-then-mark` and `ack-after-commit` redeliver-and-dedup on
  crash.** A relay that crashes after publishing but before its claim commits
  leaves the rows unpublished; the next claim republishes them. A consumer
  that crashes after committing its dedup/dispatch transaction but before
  acking (AMQP) redelivers the message on restart. Both land on consumer-side
  deduplication, which absorbs the duplicate.
- **Retention does not drop unpublished rows by default.** The produce/relay
  path never dead-letters — a stuck lane retries with backoff. When a row
  passes the retention period (default 7 days) while it is still unpublished,
  retention keeps its partition, reports it, and exits with a non-zero code
  (see [../postgresql/RETENTION.md](../postgresql/RETENTION.md)).
  The partition grows until the relay publishes the rows. Only the opt-in
  `--drop-unpublished` drops such a row. Alert on relay backlog and on the
  exit code of the maintain command.
- **A webhook claim is a lease.** The webhook delivery loop commits a lease
  before each request. After a crash, the claimed rows are due again when the
  lease ends, with no attempt used; a second request has the same
  `webhook-id` (see [WEBHOOKS.md](WEBHOOKS.md)).

There is no reconnect logic to tune. After a container exits, the kubelet
starts it again with an exponential delay (by default 10 s, 20 s, 40 s, and so
on, at most 5 minutes), and it resets the delay after the container ran for 10
minutes with no problem (see [Worker limits](#worker-limits) for the effect
on a limit stop).

## Scheduled commands

Run the scheduled commands in a Kubernetes `CronJob`:

| Command | Schedule |
| --- | --- |
| `message-broker:outbox:maintain` | Once a day. |
| `message-broker:webhook:maintain` | Once a day, when the application uses webhooks. |
| `message-broker:dedup:cleanup` | Once a day (see [DLQ_OPERATIONS.md](DLQ_OPERATIONS.md#dedup-cleanup)). |
| `message-broker:dlq:import` | At the interval that the dead letter queue of the transport needs. |

Set `concurrencyPolicy: Forbid`, so that a new run does not start while the
last run continues. Kubernetes can create the `Job` of one schedule two
times, or create none. A repeated run of each of these commands changes
nothing more, and a run that overlaps another can fail, but the next run
completes the work. Keep `--premake-days` of the two maintain commands at 2 or
more (the default is 3), so that a missed run does not leave a day with no
partition. An exit code that is not `0` fails the `Job` (after the retries of
`backoffLimit`), so alert on failed `Job`s: for example, `outbox:maintain`
exits with code `1` when it keeps an expired partition with unpublished rows.

`setup:schema` and `webhook:setup` run once at each deployment, for example
in a Kubernetes `Job` before the rollout.

## Graceful shutdown needs ext-pcntl

SIGTERM/SIGINT handling for relays, consumers and the webhook delivery loop is
registered only when `ext-pcntl` is loaded. Without it, the signal keeps its default action. This
was measured on PHP 8.4 with no `pcntl`:

- **The process is PID 1 of its container** (the usual case on Kubernetes):
  the kernel does not deliver a signal with the default action to PID 1, so
  the process ignores SIGTERM and SIGINT. Kubernetes sends SIGKILL at the end
  of the termination grace period (exit status 137), so the stop is slow and
  also not graceful.
- **The process is not PID 1** (for example, when an init process such as
  tini is the entrypoint of the image): SIGTERM and SIGINT end the process at
  once (exit status 143 and 130). There is no graceful shutdown. The current
  delivery, batch or webhook pass is not completed. The broker redelivers the
  delivery, the relay publishes the batch again, the claimed webhook rows are
  due again when their lease ends, and deduplication absorbs the repeat.

Load `ext-pcntl` in the container image. Use `--require-signals` on
`message-broker:relay:run`, `message-broker:consume` and
`message-broker:webhook:run`, so that a container with no `ext-pcntl` fails
at startup, and not at the first SIGTERM of a scale-down or a rollout:

```bash
php bin/console message-broker:relay:run orders --require-signals
php bin/console message-broker:consume orders --require-signals
```

**Termination grace period.** On a scale-down, a rollout or a node drain,
Kubernetes sends SIGTERM to PID 1 of each container and sends SIGKILL when
`terminationGracePeriodSeconds` ends (default 30 seconds). After SIGTERM, a
consumer completes its current delivery, a relay its current batch and a
webhook delivery worker its current pass (one `send()` call can last up to
`maxSendMs()` of the sender), then the process exits. Set the grace period
longer than the longest of these. The time of a `preStop` hook counts against
the same grace period. A process that SIGKILL stops loses no message, as after
a crash: the open transaction of a relay or a consumer rolls back, the broker
redelivers the unacked deliveries (on a quorum queue, each with one more delivery count, see
[The count after an uncontrolled stop](../amqp/CONSUMER.md#the-count-after-an-uncontrolled-stop)),
and the rows that a webhook delivery worker claimed are due again when their
lease ends. Deduplication of the consumer, or of the webhook receiver by
`webhook-id`, absorbs the repeat.

## Worker limits

`message-broker:relay:run`, `message-broker:consume` and
`message-broker:webhook:run` take `--limit`, `--time-limit` and
`--memory-limit`; `message-broker:consume` also takes
`--failure-limit`. A worker that stops after a limit starts again with a fresh
process, so memory growth and long-lived connections do not build up. The
options and the callable type are in
[CLI_REFERENCE.md](CLI_REFERENCE.md#message-brokerrelayrun-lane) (relay) and
[CLI_REFERENCE.md](CLI_REFERENCE.md#message-brokerconsume-name) (consumer) and
[CLI_REFERENCE.md](CLI_REFERENCE.md#message-brokerwebhookrun) (webhook delivery loop).

A limit stop ends the process with exit code `0`. **The container must start
again after each exit, exit code `0` included.** A `Deployment` does this: its
pod template allows only `restartPolicy: Always`. Do not run a worker as a
`Job`, or as a pod with `restartPolicy: OnFailure` or `Never`: after a limit
stop, the lane or the queue then has no worker.

Plan for the restart delay of the kubelet after a limit stop too: the
Kubernetes documentation gives no exception for exit code `0`. Thus a worker
that stops again in less than 10 minutes can wait longer before each new
start, up to 5 minutes by default, and its pod can show `CrashLoopBackOff`.
Choose `--limit` and `--time-limit` so that a worker runs for 10 minutes or
more.

Set `--memory-limit` below the PHP `memory_limit`, and the PHP
`memory_limit` below the memory limit of the container: a process above the
memory limit of the container ends with SIGKILL from the kernel, with no PHP
error. The relay and the webhook
delivery loop check the memory after each pass and the consumer after each delivery, so a pass or a delivery
that goes above the PHP `memory_limit` ends the process with a fatal error
before the check. The check uses
`memory_get_usage(true)`, the memory that PHP took from the system; it seldom
goes down, so a worker can stop after a short peak, which a recycle limit
accepts.

**Failure limit (consumer).** `--failure-limit=<n>` stops the consumer after
`<n>` handler failures in one run: deliveries that the retry policy retried,
dead-lettered or discarded. It is a total, not a sequence of consecutive
failures. A worker whose handler fails at each delivery (for example, because a
dependency is down) then stops and starts again with fresh connections, instead
of running on with the same connections. A consumer limit stop is a graceful
stop: the consumer returns its buffered deliveries with no delivery count (see
[the return rule](../amqp/CONSUMER.md#the-return-rule)).

## Observability

There is no built-in metrics interface. Instead, inject:

- **A PSR-3 `LoggerInterface`** on `Relay` and `DeliveryProcessor` — failures and
  backoff decisions are logged (`warning`/`error`); the default
  `NullLogger` is silent.
- **A `BrokerEvents` listener** — an optional lifecycle hook fired on the
  lifecycle transitions: `PRODUCED`, `RELAYED`, `DISPATCHED`, `DEDUPLICATED`,
  `RETRIED`, `DISCARDED`, `DEAD_LETTERED`, `REPLAYED`, `REPLAY_SKIPPED`,
  `ABANDONED`.
  `REPLAYED` (`message.replayed`) comes after the commit of a replay; its
  context is `message_id`, `message_name`, `lane` and `target` (the `source`
  of the dead letter). `REPLAY_SKIPPED` (`message.replay_skipped`) comes from
  each consumer that acks a replay for a different queue; its context is
  `consumer`, `source`, `target`, `message_id` and `message_name`. In normal
  operation each replay gives one `REPLAY_SKIPPED` for each other queue that
  binds the routing key; skips with no processing on any queue show a target
  that no consumer serves (see [DLQ_OPERATIONS.md](DLQ_OPERATIONS.md)).
  `ABANDONED` (`message.abandoned`) comes from `message-broker:outbox:abandon`
  when it marks one or more rows; its context is `message_id`, `count` and
  `reason` (a string or `null`).
- **An `ErrorHandler`** — invoked with full context whenever a failure is
  handled (backoff scheduled, message dead-lettered).

```php
use Freyr\MessageBroker\Observability\BrokerEvents;

final class MetricsBrokerEvents implements BrokerEvents
{
    public function record(string $event, array $context = []): void
    {
        // e.g. increment a counter tagged by $event, ship $context to your metrics backend
    }
}
```

Derive operational metrics from the `outbox_messages`, `message_deduplication`,
and `dead_letters` tables plus these events:

- **Throughput** — the `RELAYED`/`DISPATCHED` event rate.
- **Retry rate** — the `RETRIED` event rate; `DISCARDED` counts the dropped messages.
- **Relay backlog** — the age of the oldest unpublished row that is not
  abandoned (`published_at IS NULL AND abandoned_at IS NULL`) per lane.
- **Abandoned rows** — the count of rows with `abandoned_at IS NOT NULL` in
  the live partitions. Each one is a message that an operator stopped (see
  [RELAY.md](RELAY.md), *A row that the transport refuses*).
- **Retention health** — the exit code of `message-broker:outbox:maintain`,
  its report of kept partitions with unpublished rows, and the partition of tomorrow
  (it must exist). The command also prints the abandoned rows that it dropped.
- **Consumer lag** — broker queue depth (AMQP).
- **DLQ depth** — `SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL`,
  optionally grouped by `source`/`message_name`. `message-broker:dlq:stats
  --not-replayed` gives the open dead letters by message name, work queue and
  error class (see [DLQ_OPERATIONS.md](DLQ_OPERATIONS.md)).

## Scaling

**The unit of scale is the pod.** One pod runs one worker process, so "add
consumer processes" means "add replicas to the consumer `Deployment`". HPA or
KEDA changes the number of replicas: for example, scale a consumer on the
depth of its queue (KEDA has a RabbitMQ scaler) and a relay on the count of
due unpublished rows of its lane (KEDA has a PostgreSQL scaler that runs a
query). Set the maximum replica count of a relay to the worker count that
*Batch size and worker count* below gives (2 to 4 for each lane). Each pod
holds its own database connection, so the replicas of all workers together
must fit `max_connections` of PostgreSQL; behind a transaction-mode pooler,
they must fit `max_client_conn`, and the server pool limits the concurrent
transactions (see
[../postgresql/CONNECTION_POOLING.md](../postgresql/CONNECTION_POOLING.md)).
A scale-down is a SIGTERM: with `ext-pcntl`, and when the current delivery
ends inside the grace period, a consumer returns its buffered deliveries with
no delivery count (see [the return rule](../amqp/CONSUMER.md#the-return-rule)).

**There is no ordering.** Rows publish in any order, and consumers must be
order-insensitive. AMQP routes by message name. See
[../amqp/TRANSPORT.md](../amqp/TRANSPORT.md).

**Consumers: one commit per message.** Each delivery is one transaction (the
dedup insert and your handler), one commit and one ack. The disk flush of the
commit limits one consumer process. A local benchmark (empty handler, prefetch
32, persistent messages) gave these numbers:

| Consumer processes | msg/s |
|---|---|
| 1 | 740 |
| 2 | 1,190 |
| 4 | 3,500 |
| 8 | 6,500 |

The throughput grows faster than the process count, because PostgreSQL
flushes concurrent commits together. Add consumer processes to one queue for
more throughput. A prefetch above 32 adds little. Do not set
`synchronous_commit = off` for the consumer connection: after a database
crash, a message that is already acked can lose its processing.

**With business work, the handler time limits a consumer.** A handler that
takes 30–50 ms makes the commit cost small. The throughput then grows linearly
with the number of consumer processes. A local benchmark (the handler sleeps
inside the consumer transaction, 300 messages for each process) gave these
numbers in msg/s:

| Consumer processes | Handler 30 ms | Handler 50 ms |
|---|---|---|
| 4 | 107 | 70 |
| 8 | 212 | 139 |
| 12 | 328 | 206 |
| 16 | 443 | 276 |

This is 80–87% of the limit of the sleep alone (processes ÷ handler time).
Prefetch 1 and prefetch 32 give the same result within 3%. To get more
throughput with slow handlers, add consumer processes. Run the benchmark with
`make bench-consumer ARGS="<messages> <processes> <prefetch> <handler-ms>"`.

**Scale by adding workers to a lane.** Run N identical `Relay` worker
processes (N replicas) against one lane. Each claims a batch of eligible rows via
`FOR UPDATE SKIP LOCKED` inside a transaction owned by the outbox store,
publishes the batch with publisher confirms, and marks the rows published.
Workers never block each other, and a crashed worker's claimed rows are
instantly reclaimable. A broker failure backs off the rows of the failed
publish and stops the worker; a nack backs off only the nacked rows (see
[RELAY.md](RELAY.md)).

**Batch size and worker count.** The default batch is 500 rows: one claim, one
confirm wait and one update for each batch. A local benchmark (one machine,
one queue, persistent messages, publisher confirms) gave these numbers:

| Workers | Batch 100 | Batch 500 |
|---|---|---|
| 1 | 17,000 msg/s | 29,000 msg/s |
| 2 | 33,000 msg/s | 49,000 msg/s |
| 4 | 52,000 msg/s | 72,000 msg/s |
| 8 | 51,000 msg/s | 25,000 msg/s |

PostgreSQL is not the limit: the claim and the update alone reach about
127,000 msg/s with 4 workers. The limit is the RabbitMQ queue. At 8 workers,
the workers spend most of the time on confirm waits, and the throughput falls.
Start with 2 to 4 workers per lane. For more throughput, use more lanes and
queues, not more workers on one queue. Measure your own setup with
`make bench ARGS="<rows> <workers> <batch>"`.

**Lanes isolate workloads.** A lane is a named drain of the outbox table. Use
separate lanes so that a slow or failing workload does not delay another one.

**Watch row age.** A single stuck row does not stall a lane, because other
rows overtake it. Watch the age of the oldest unpublished row
(`published_at IS NULL`) per lane.
