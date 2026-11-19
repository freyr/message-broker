# Production

Operational guidance for running relays and consumers in production: the
deployment model, graceful shutdown, observability and scaling. Outbox
retention and connection pooling are in the documents of the PostgreSQL
adapter: [../postgresql/RETENTION.md](../postgresql/RETENTION.md) and
[../postgresql/CONNECTION_POOLING.md](../postgresql/CONNECTION_POOLING.md).

## Deployment model — crash-only under a supervisor

Run one or more relay workers per lane and one or more consumer processes under a process
supervisor (systemd, a Kubernetes `Deployment`, Supervisor, etc.) configured
with restart-always. On any broker or database failure, the process
**crashes by design** — there is no in-process reconnect logic; the
supervisor is what restarts it.

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

Tune restart backoff (how fast the supervisor retries a crashed process),
not reconnect logic — there is none to tune.

## Graceful shutdown needs ext-pcntl

SIGTERM/SIGINT handling for relays and consumers is registered only when
`ext-pcntl` is loaded. Without it, the signal keeps its default action. This
was measured on PHP 8.4 with no `pcntl`:

- **The process is not PID 1** (for example under an init process such as
  `docker run --init` or tini): SIGTERM and SIGINT end the process at once
  (exit status 143 and 130). There is no graceful shutdown. The current
  delivery or batch is not completed. The broker redelivers the delivery,
  the relay publishes the batch again, and deduplication absorbs the repeat.
- **The process is PID 1 of a container**: the kernel does not deliver a
  signal with the default action to PID 1, so the process ignores SIGTERM
  and SIGINT. The runtime sends SIGKILL after its stop timeout (exit status
  137), so the stop is slow and also not graceful. Run the process under an
  init process, or load `ext-pcntl`.

Use `--require-signals` on `message-broker:relay:run` and
`message-broker:consume` to fail fast at startup if `ext-pcntl` is missing,
rather than discovering it the first time a supervisor sends SIGTERM:

```bash
php bin/console message-broker:relay:run orders --require-signals
php bin/console message-broker:consume orders --require-signals
```

## Worker limits

`message-broker:relay:run` takes `--limit`, `--time-limit` and `--memory-limit`.
A worker that stops after a limit starts again with a fresh process, so memory
growth and long-lived connections do not build up. The options and the callable
type are in [CLI_REFERENCE.md](CLI_REFERENCE.md#message-brokerrelayrun-lane).

A limit stop ends the process with exit code `0`. **The supervisor must start a
new process after each exit, exit code `0` included.** A supervisor that restarts
only after a failure leaves the lane with no worker.

| Supervisor | Setting that restarts after exit code 0 | Setting that does not |
| --- | --- | --- |
| systemd | `Restart=always` | `Restart=on-failure` |
| Supervisor | `autorestart=true` | `autorestart=unexpected` |
| Kubernetes | `restartPolicy: Always` (the default of a `Deployment`) | `OnFailure` or `Never` |

Set `--memory-limit` below the PHP `memory_limit`. The relay checks the memory
after each pass, so a pass that goes above the PHP `memory_limit` ends the
process with a fatal error before the check. The check uses
`memory_get_usage(true)`, the memory that PHP took from the system; it seldom
goes down, so a worker can stop after a short peak, which a recycle limit
accepts.

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
processes against one lane. Each claims a batch of eligible rows via
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
