# Production

Operational guidance for running relays and consumers in production: the
deployment model, graceful shutdown, observability, scaling, and outbox retention.

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
- **The retention period is the only data-loss path.** The produce/relay
  path never dead-letters — a stuck lane retries with backoff until the row
  passes the retention period (default 7 days). Then retention drops the row
  with its partition and reports it. Alert on relay backlog well before that
  limit.

Tune restart backoff (how fast the supervisor retries a crashed process),
not reconnect logic — there is none to tune.

**Running `AmqpConsumer` sequentially over one connection.** `run()` accepts
`messageLimit`/`idleTimeoutSec` — these exist for tests and batch-style
operation; production workers normally run with neither, blocking forever.
If you do run several consumers sequentially over one shared AMQP
connection (e.g. a batch job draining multiple queues in turn), close the
previous consumer's channel before starting the next one: prefetched-but-
unacked deliveries are requeued only on channel close, not automatically
between successive `run()` calls.

## Connection pooling (pgbouncer)

All components are safe behind a transaction-mode pooler. No component holds
a session-scoped lock: the relay claim and the consumer deduplication each run
inside one transaction, and the producer joins your own transaction. Any pool
mode works, including `pool_mode = transaction`.

## Graceful shutdown needs ext-pcntl

SIGTERM/SIGINT handling for relays and consumers is registered only when
`ext-pcntl` is loaded. Without it, the signal is ignored, and the process
can only be stopped with SIGKILL — which drops in-flight work (an
unacked delivery, a lane mid-batch).

Use `--require-signals` on `message-broker:relay:run` and
`message-broker:consume` to fail fast at startup if `ext-pcntl` is missing,
rather than discovering it the first time a supervisor sends SIGTERM:

```bash
php bin/console message-broker:relay:run orders --require-signals
php bin/console message-broker:consume orders --require-signals
```

## Observability

There is no built-in metrics interface in 1.0. Instead, inject:

- **A PSR-3 `LoggerInterface`** on relays and consumers — failures and
  backoff decisions are logged (`warning`/`error`); the default
  `NullLogger` is silent.
- **A `BrokerEvents` listener** — an optional lifecycle hook fired on the
  success path: `PRODUCED`, `RELAYED`, `DISPATCHED`, `DEDUPLICATED`,
  `DEAD_LETTERED`, `REPLAYED`.
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
- **Relay backlog** — the age of the oldest unpublished row
  (`published_at IS NULL`) per lane.
- **Retention health** — the exit code of `message-broker:outbox:maintain`,
  its warnings about dropped unpublished rows, and the partition of tomorrow
  (it must exist).
- **Consumer lag** — broker queue depth (AMQP).
- **DLQ depth** — `SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL`,
  optionally grouped by `source`/`message_name`.

## Scaling

**There is no ordering.** Rows publish in any order, and consumers must be
order-insensitive. AMQP routes by message name. See
[TRANSPORTS.md](TRANSPORTS.md).

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

**JSON or Avro.** Avro bodies are smaller, but the Avro library is pure PHP,
so encode and decode use more CPU. A local benchmark (20,000 messages, two
runs) gave these numbers. "order" is an order event with 20 fields, an address
and 5 line items; "small" has 3 fields.

| | JSON order | Avro order | JSON small | Avro small |
|---|---|---|---|---|
| Body size | 988 B | 451 B | 88 B | 49 B |
| Encode (CPU, per message) | 1.4 µs | 22 µs | 0.2 µs | 2.3 µs |
| Decode (CPU, per message) | 3.6 µs | 33 µs | 0.6 µs | 3.7 µs |
| Produce, 100 per transaction | 14,300 msg/s | 12,300 msg/s | 17,800 msg/s | 16,700 msg/s |
| Relay, 1 worker | 18–24k msg/s | 20–27k msg/s | 26–29k msg/s | 26–29k msg/s |
| Relay transfer | 18–24 MB/s | 9–12 MB/s | 2.5 MB/s | 1.4 MB/s |
| Outbox size for 20,000 rows | 24.5 MB | 12.8 MB | 5.1 MB | 4.3 MB |

- The CPU cost of Avro is small next to a commit (about 1 ms) or a handler.
  The consumer throughput is the same for both formats.
- For a large payload, Avro halves the network transfer, the broker memory
  and the outbox size. For a small payload, the row and header overhead is
  larger than the body, so the gain is small.
- Choose the format for the schema contract (compatibility checks in CI,
  reader and writer resolution), not for speed.

Run the benchmark with
`make bench-format ARGS="<json|avro> <messages> <small|order> [consumers]"`.

**Scale by adding workers to a lane.** Run N identical `AmqpRelay` worker
processes against one lane. Each claims a batch of eligible rows via
`FOR UPDATE SKIP LOCKED` inside a transaction owned by the outbox store,
publishes the batch with publisher confirms, and marks the rows published.
Workers never block each other, and a crashed worker's claimed rows are
instantly reclaimable. A broker failure backs off the rows of the failed
publish and stops the worker; a nack backs off only the nacked rows (see
[TRANSPORTS.md](TRANSPORTS.md)).

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

## Outbox retention

The relay never deletes rows. It sets `published_at`, and the rows stay until
retention drops their partition. `outbox_messages` is partitioned by
`created_at`, with one partition per UTC day (`outbox_messages_pYYYYMMDD`) and
no default partition. An insert for a day with no partition fails, and the
producer's transaction fails with it, so create partitions ahead of time.

Run `message-broker:outbox:maintain` once a day (cron):

```bash
php bin/console message-broker:outbox:maintain --retention-days=7 --premake-days=3
```

The command:

- creates the missing daily partitions from today to today plus
  `--premake-days` (`CREATE TABLE`, then `ATTACH PARTITION`);
- drops each daily partition that lies wholly before the cutoff (today
  midnight UTC minus `--retention-days`): `DETACH PARTITION ... CONCURRENTLY`,
  then `DROP TABLE` on the detached table;
- finishes a detach that an earlier run did not complete
  (`DETACH PARTITION ... FINALIZE`), and drops an expired table that an earlier
  run detached.

The retention period (`--retention-days`, default 7) is also the age limit of
an unpublished row. An expired partition is dropped even when it still holds
unpublished rows; the command reports them as a warning. A relay that stays
broken for longer than the retention period loses those messages, so alert on
the age of the oldest unpublished row well before that limit.

**Locks.** No step takes an `ACCESS EXCLUSIVE` lock on `outbox_messages`.
`ATTACH PARTITION` and `DETACH PARTITION ... CONCURRENTLY` take only
`SHARE UPDATE EXCLUSIVE`, which does not block inserts, claims or updates. The
detach waits until the transactions that use `outbox_messages` end, so a long
business transaction delays the command but blocks nobody. The command must
run outside a transaction. A plain `DROP TABLE` or `DETACH PARTITION` of a
partition takes `ACCESS EXCLUSIVE` on the parent and queues every producer
behind it; do not run them on this table by hand.

It exits with code 0, also when it dropped unpublished rows, and with code 2
on invalid options. Use `--dry-run` to preview the changes.

### With pg_partman

pg_partman can create the partitions. Its default name for a daily partition,
`outbox_messages_pYYYYMMDD`, is the name this library uses, so
`outbox:maintain` skips the partitions that pg_partman made and drops them when
they expire. Configure pg_partman like this:

- **No default partition:** `create_parent(..., p_default_table := false)`.
  With a default partition, `DETACH PARTITION ... CONCURRENTLY` fails.
- **No retention:** keep `retention` NULL in `part_config`. pg_partman retention
  runs a plain `DETACH PARTITION`, which takes `ACCESS EXCLUSIVE` on the parent.
  Let `outbox:maintain` drop the partitions.
- **UTC:** run the database in UTC, so pg_partman's day boundaries agree with
  the UTC days of this library.

Monitor three signals: the age of the oldest unpublished row per lane, the
warnings of the maintain command, and the partition of tomorrow.
