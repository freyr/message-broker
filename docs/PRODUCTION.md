# Production

Operational guidance for running relays and consumers in production: the
deployment model, graceful shutdown, observability, and scaling.

## Deployment model — crash-only under a supervisor

Run one relay per lane and one (or more) consumer processes under a process
supervisor (systemd, a Kubernetes `Deployment`, Supervisor, etc.) configured
with restart-always. On any broker or database failure, the process
**crashes by design** — there is no in-process reconnect logic; the
supervisor is what restarts it.

This is safe because:

- **The lane advisory lock self-releases on connection close.**
  `tryAcquireLane`/`releaseLane` are backed by session-scoped locks
  (`GET_LOCK` on MySQL, `pg_try_advisory_lock` on PostgreSQL): a crashed
  relay's lock disappears the instant its connection dies, so a restarting
  or standby relay can take the lane over immediately.
- **`publish-then-delete` and `offset-after-commit` redeliver-and-dedup on
  crash.** A relay that crashes after publishing but before deleting the
  outbox row republishes its batch on restart. A consumer that crashes after
  committing its dedup/dispatch transaction but before acking (AMQP) or
  committing the Kafka offset redelivers the message on restart. Both land
  on consumer-side deduplication, which absorbs the duplicate.
- **There is no data-loss path.** Nothing is deleted from the outbox until a
  publish is confirmed. The produce/relay path never dead-letters — a stuck
  lane retries with backoff indefinitely, which is an operational alert, not
  silent loss.

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
- **Consumer lag** — outbox row age (the oldest `created_at`/`available_at`
  per lane) and, on Kafka, standard consumer-group lag.
- **DLQ depth** — `SELECT COUNT(*) FROM dead_letters WHERE replayed_at IS NULL`,
  optionally grouped by `source`/`message_name`.

## Scaling & ordering

**One relay per lane, enforced by the lock.** For the ordered relay
(`AmqpRelay`/`KafkaRelay`), the lane advisory lock guarantees exactly one
active owner; a second relay process started on the same lane stands by,
retrying `tryAcquireLane` on every idle tick, and takes over the instant the
active relay releases (clean shutdown or crash).

**Competing (opt-in): `CompetingAmqpRelay`.** For order-insensitive AMQP
workloads, run N identical worker processes against one lane; each claims a
batch of eligible rows via `FOR UPDATE SKIP LOCKED` inside a transaction
owned by the outbox store, publishes while holding the claim, and deletes
on success. Workers never block each other, and a crashed worker's claimed
rows are instantly reclaimable — its locks die with its connection. There is
**no ordering promise**: rows overtake freely, across workers and past
backing-off rows; id order is a FIFO bias, not a contract. A publish failure
backs off the whole claimed batch, each row at its own attempts-derived
delay. This mode is AMQP-only; Kafka keeps the ordered drain.

**Lane sharding scales ordered throughput.** Since one lane has one active
ordered relay, scale by fanning production across `lane-1..N` (e.g. a hash
of the message key) and running one ordered relay per lane. This preserves
per-lane ordering while parallelizing across lanes.

**Watch different signals per mode.** Ordered mode: a backing-off lane head
blocks everything behind it, so watch for a blocked lane (the oldest
unrelayed row growing stale). Competing mode: a blocked head cannot block
the lane — rows overtake it — so watch **outbox row age** instead (the
oldest `available_at`/`created_at` across the lane), since a slowly-draining
lane will not show up as a single stuck head.

**Strict per-key FIFO is Kafka-only.** AMQP routes by message name and has
no per-key ordering knob — best-effort per-key ordering on AMQP was
researched and is postponed. If your workload needs strict per-key FIFO,
produce to Kafka. See [TRANSPORTS.md](TRANSPORTS.md).

**Lock-key changes need stop-all-relays deploys.** The advisory-lock key
derivation (`Platform::tryAcquireLaneSql`/`releaseLaneSql`) is part of the
relay's coordination contract. If a deploy changes how the lock key is
derived for a lane, two relay versions running simultaneously — old and new
— can both believe they own the same lane, because they compute different
lock keys and neither sees the other's lock. Ordering breaks for the overlap
window (consumer dedup still absorbs the resulting duplicates, but total
order is not held during it). Deploys that change lock-key derivation must
stop all relays for the affected lanes, then start the new version — no
rolling swap.
