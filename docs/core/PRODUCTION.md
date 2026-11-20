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

## Webhook flow control benchmark

`make bench-webhook ARGS="<store> <lanes> <workers> <rps> <burst> <lane-rps> <batch> <endpoint-ms> <seconds>"`
(`tools/webhook-bench.php`) fills `webhook_deliveries` with a backlog for the
full run and starts N delivery workers: `DeliveryLoop` with
`PostgreSqlDeliveryStore`, `PostgreSqlCircuitBreaker`, the rate limit store
(`postgresql` or `redis`) and `HttpDeliverySender` against the test HTTP
server (`tools/webhook-test-server/server.mjs`). The server counts the
requests. The tool prints:

- The most requests in a sliding window of 10 s (step 1 s), for all lanes and
  for any lane, with the bounds (10 × rate + burst) × 1.01.
- For each steady window (10 s windows that start 10 s or more after the first
  request and end 10 s or more before the last request): the requests, the
  delivered rate and the limited grants (each lane grant below its wanted
  count, also 0).
- D: the longest time from the end of an `acquire()` to the first request of
  its pass at the server.
- The `acquire()` duration (p50, p99). For PostgreSQL, the share of the
  samples of `pg_stat_activity` (one each 10 ms) with a session that waits
  for a lock on `webhook_rate_limit`.

`make bench-webhook-server ARGS="<rps> <delay-ms> <seconds>"` measures the
test server alone. The values below are relative: one laptop, all services in
Docker, the server on the same machine.

**Machine.** Apple M3 Max (14 cores), 36 GB RAM, macOS 26.5. OrbStack 7.0.14
(Docker 29.4.0) with 10 CPUs and 16 GB RAM. PostgreSQL 16 (alpine image,
default settings), Redis 7 (alpine image), PHP 8.4, Node.js 22. Other idle
containers ran on the same machine.

**Settings of each run.** 20 lanes, a row limit of 25 for each lane (the
default), a batch size of 100, the connections for each host of the sender
equal to the batch size, endpoints that answer 200 after 50 ms, 60 s for each
worker, the default circuit breaker policy (no lane opened in any run), and
the defaults of `DeliveryLoopConfig` for the other values.

**The test server alone.** At 1,200 requests each second with a delay of
50 ms for 30 s, the server logged 1,197 to 1,203 requests in each second
(0 errors, p99 54 ms at the client). At 2,400 requests each second: 2,392 to
2,407 (p99 54 ms).

### Global rate limit

RPS 1,000, burst 100, no lane limit. Three runs for each store and worker
count. A run is valid only when the limit was active: each steady window has
a limited grant and 9,800 requests or more. All 18 runs were valid. In each
run, the global bucket cut nearly every `acquire()` (the sum of the grants was
below min(batch size, wanted)), so the bucket and not the batch size set the
rate. Bound for all lanes: 10,201 requests in 10 s.

| Store | Workers | Max in 10 s, all lanes (3 runs) | Max in 10 s, any lane | Delivered rate in the steady windows | Limited grants in a steady window | D max | Acquire p50 / p99 | Lock wait share |
|---|---|---|---|---|---|---|---|---|
| PostgreSQL | 1 | 10,059 / 10,071 / 10,098 | 516–517 | 996.5–1,005.9 /s | 2,720–2,800 | 25.7–28.4 ms | 1.8 / 3.4–4.0 ms | 0% |
| PostgreSQL | 2 | 10,055 / 10,090 / 10,089 | 522–524 | 994.0–1,005.5 /s | 5,360–5,560 | 28.4–32.5 ms | 2.4–2.5 / 8.0–8.4 ms | 0.77–0.80% |
| PostgreSQL | 4 | 10,059 / 10,069 / 10,081 | 524–533 | 995.5–1,006.9 /s | 10,620–11,320 | 25.2–30.9 ms | 3.6–3.9 / 10.9–11.0 ms | 4.5–5.0% |
| Redis | 1 | 10,058 / 10,072 / 10,097 | 516–522 | 994.4–1,006.9 /s | 2,800–2,940 | 17.7–27.4 ms | 0.13 / 0.4–0.6 ms | — |
| Redis | 2 | 10,065 / 10,106 / 10,095 | 520–525 | 997.4–1,004.6 /s | 5,720–6,000 | 26.8–34.9 ms | 0.12 / 2.0–2.2 ms | — |
| Redis | 4 | 10,054 / 10,083 / 10,060 | 524–526 | 998.8–1,002.9 /s | 11,800–12,480 | 19.0–24.0 ms | 0.10 / 2.3 ms | — |

Each window of each run stays within the bound. The highest count, 10,106,
is 6 requests above 10 × RPS plus the burst (10,100), inside the tolerance of
1%. D stays below 35 ms, so the permits that a worker holds before its
requests can move at most about 35 requests into a window.

### Lane limit

8 workers, a lane rate of 40 with the default burst of 4 for each of the 20
lanes, RPS 1,000 with a burst of 100, three runs for each store. A run is
valid only when each lane had a limited grant in each steady window: all 6
runs were valid (20 of 20 lanes in each steady window). Bound for one lane:
408 requests in 10 s.

| Store | Max in 10 s, any lane (3 runs) | Max in 10 s, all lanes | Delivered rate in the steady windows | Limited grants in a steady window | D max | Acquire p50 / p99 | Lock wait share |
|---|---|---|---|---|---|---|---|
| PostgreSQL | 403 / 404 / 404 | 8,060–8,080 | 796.0–802.0 /s | 24,500–26,840 | 20.1–42.4 ms | 4.5–4.7 / 16.6–33.2 ms | 17.8–20.2% |
| Redis | 403 / 402 / 402 | 8,040–8,060 | 796.0–804.0 /s | 27,580–29,000 | 17.4–17.9 ms | 0.21 / 2.2–2.3 ms | — |

The lane buckets hold the 20 lanes at 800 requests each second, below the
global rate. With 8 workers on PostgreSQL, a session waits for the lock of a
bucket row in about one sample of five, and the acquire p99 grows to 17–33 ms.
The limit holds; the lock wait costs time in the worker, not accuracy. The
Redis store has no row lock and a p99 near 2 ms.

### Scale

PostgreSQL store, RPS 2,000 with a burst of 200, so that the limit is above
the target of 1,000 requests each second. Three runs for each worker count.

| Workers | Delivered rate in the steady windows | Max in 10 s, all lanes (bound 20,402) | Global bucket cut an acquire | D max | Acquire p50 / p99 | Lock wait share |
|---|---|---|---|---|---|---|
| 1 | 1,340–1,380 /s | 13,700–13,804 | 0 of about 808 calls | 21.3–27.7 ms | 1.8–1.9 / 2.8–4.1 ms | 0% |
| 2 | 1,994.6–2,008.2 /s | 20,146–20,214 | 760–782 of 1,536–1,570 | 26.9–44.2 ms | 2.4–2.6 / 7.4–7.6 ms | 0.70–0.73% |
| 4 | 1,987.2–2,012.2 /s | 20,174–20,220 | 2,231–2,246 of 2,976–2,996 | 25.9–31.7 ms | 3.2–3.3 / 11.1–11.8 ms | 3.3–3.7% |

One worker delivers 1,340 requests each second or more for 20 lanes with
endpoints of 50 ms; its pass time sets that rate, not the limit. Two workers
reach the limit of 2,000. Run more workers than this for headroom, because a
slower endpoint makes each pass longer.
