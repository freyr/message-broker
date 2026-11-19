# DLQ Operations

Consumption failures that exhaust their retry budget land in the shared
`dead_letters` table — the same table regardless of transport. This document
covers the operator-facing commands for inspecting, replaying, purging, and
housekeeping the DLQ. See [CLI_REFERENCE.md](CLI_REFERENCE.md) for the full
option list of each command.

## One open row for each message and work queue

The table holds at most one open row (not replayed) for one message ID and
one work queue (`source`). The partial unique index `uq_dead_letters_open`
enforces this rule:

- A message that fails again while it has an open row does not add a row. The
  open row keeps its `id`, and its error data, attempts, `failed_at`, body,
  headers and `replayable` mark change to the data of the latest failure. A
  stop of the consumer between the dead letter write and the ack thus gives one
  row, not two.
- A message that fails again after a replay gets a new row. The replayed row
  stays for audit.
- A store of a dead letter whose `id` is already a row changes nothing.
- A raw dead letter with no message ID gets the ID `unknown-<UUID v7>`, so
  each such message has its own row.

## The replayable mark

The column `replayable` is `false` for a row whose body is the raw wire bytes
of the delivery: a malformed message, or a message at the delivery limit that
the consumer cannot deserialize. It is `true` for a row with the canonical
document, which a replay can decode. A row from before 0.10.0 has `true`, also
when its body is raw bytes. `dlq:list` and `dlq:show` print the mark.

## Filter options

`dlq:list`, `dlq:stats`, `dlq:purge` and `dlq:replay --all` take the same
filter options. All set options combine with AND:

| Option | Selects the rows with |
| --- | --- |
| `--name` | this message name |
| `--source` | this work queue |
| `--error` | this error class (the full class name; no subclass, no prefix match) |
| `--message-id` | this message ID |
| `--since` | `failed_at` at or after the time (inclusive) |
| `--until` | `failed_at` before the time (exclusive) |
| `--min-attempts` | at least this many attempts (an integer of 1 or more) |
| `--replayed` / `--not-replayed` | a replay mark / no replay mark |
| `--replayable` / `--not-replayable` | `replayable` true / false |

`--since` and `--until` take a duration (`30s`, `15m`, `24h`, `7d`: now minus
the duration) or an ISO 8601 date-time with an offset or `Z`
(`2026-06-10T08:30:00Z`). The date-time form selects an incident window that
started days ago. Both flags of one pair together, a bad duration, a bad
date-time, `--min-attempts 0`, or a `--since` that is not before `--until`
end the command with exit code 2; the command does not touch the table.

## Inspecting

`dlq:list` lists dead letters newest-first, with the filter options and
`--limit` and `--offset` for paging. `dlq:show <id>` prints one dead letter in
full — metadata, headers, body, error class/message, and stack trace.

```bash
php bin/console message-broker:dlq:list --source=orders_q --since=24h --limit=20
php bin/console message-broker:dlq:list --error='App\PaymentFailed' --since=2026-06-10T08:00:00Z --until=2026-06-10T12:00:00Z
php bin/console message-broker:dlq:show 0190f7e2-1234-7000-8000-abc123456789
```

## Statistics

`dlq:stats` prints one row for each group of message name, work queue and
error class, with the count and the oldest and newest failure, the largest
group first. With no option it groups all rows; `--not-replayed` gives the
open dead letters:

```bash
php bin/console message-broker:dlq:stats --not-replayed
```

`dlq:stats` prints no body, no header and no error message, so its output is
safe to share in a ticket. `dlq:show` prints the body, the headers and the
trace, which can hold personal data of the payload.

## Replaying

`dlq:replay` re-enqueues a dead letter back into the outbox under a chosen
lane, so redelivery rides the normal relay path (publisher confirms). The
command inserts a new outbox row with the original message id and a new
`created_at`; the original published row can still exist until retention
drops its partition. The dead letter row is kept for audit and marked
`replayed_at` rather than deleted.

### One transaction for each replay

`ReplayService` replays one dead letter in one transaction: it sets the
replay mark first (`markReplayed()`, only when `replayed_at` is null), then
inserts the outbox row, then commits. A stop before the commit rolls back
the mark and the row together, so the dead letter stays open and the next
replay works. A stop after the commit keeps both.

When the commit fails, the outcome is not known: the connection can stop
after the server committed. The replay then reports a failure. Before any
manual change, check `replayed_at` with `dlq:show`. A second replay of a row
that did commit fails with "already replayed" and inserts nothing.

**One connection.** Give the dead letter store, the outbox store and the
transaction one connection. With PostgreSQL:

```php
$replay = new ReplayService(
    new PostgreSqlDeadLetterStore($pdo),
    new PostgreSqlOutboxStore($pdo),
    new PdoTransaction($pdo),
    new JsonWireFormat(),
);
```

When two connections cannot be avoided, the transaction must wrap the
connection of the dead letter store. Then a stop between the outbox insert
and the commit leaves an outbox row and an open dead letter, and the next
replay sends the message a second time. Consumer deduplication absorbs the
second copy only when the handler already committed for that message id. No
replay is lost in this direction. Do not wrap the connection of the outbox
store: a stop then keeps the mark and loses the outbox row, and the refusal
of a replayed row blocks a new replay. Do not call `replay()` inside a
transaction that is open on the same connection.

### Refusals

The replay refuses three cases and changes nothing:

| Case | Exception (each extends `RuntimeException`) | Single id |
| --- | --- | --- |
| No row with the id (unknown, or purged) | `DeadLetterNotFound` | exit code 1 |
| The row is replayed (`replayed_at` is set) | `AlreadyReplayed` | exit code 1 |
| The row has `replayable` false, or its body is not the canonical document | `NotReplayable` | exit code 1 |

No option replays a replayed row again. A message that failed again after
its replay has a new open row: replay that row. A repeat of a message that
was processed is deduplicated, so a second replay has no use. When two
operators replay the same row at the same time, the second one waits for the
row lock and then fails with "already replayed".

A row with `replayable` false holds the raw wire bytes; no replay can decode
it. A row from before 0.10.0 can have `replayable` true and a raw body: the
replay refuses it too, with the reason from the decode.

**Single id** replays immediately, with no confirmation prompt:

```bash
php bin/console message-broker:dlq:replay 0190f7e2-1234-7000-8000-abc123456789 --lane=orders
```

`--dry-run` with one id runs the same checks and changes nothing: it prints
"would replay" with exit code 0, or the refusal with exit code 1.

### Bulk replay

**`--all`** replays every dead letter that matches the filter options and
is not replayed: the command always adds the condition `--not-replayed`.
`--replayed` together with `--all` ends with exit code 2 and replays nothing.
By default it reads only rows with `replayable` true; `--not-replayable`
replaces this condition (each such row is then a skip, which shows the rows in
the report). It reads only rows that failed before the start of the run, or
before `--until` when `--until` is earlier: a replayed message that fails
again during the run gets a new open row, and the run does not replay it
again.

This is a batch operation, so it always goes through the dry-run-then-force
workflow:

```bash
# 1. See what would happen — count plus a preview of the first 5 candidates.
php bin/console message-broker:dlq:replay --all --source=orders_q --since=24h --dry-run

# 2. Run it for real, at most 1000 rows and 50 replays a second.
php bin/console message-broker:dlq:replay --all --source=orders_q --since=24h --limit=1000 --rate=50 --force
```

Without `--force`, a `--all` replay asks for interactive confirmation
(`[y/N]`). If standard input is not a TTY (a cron job, a CI step, a
non-interactive shell), the command **refuses to run** rather than silently
proceeding — pass `--force` explicitly for any non-interactive batch replay.

Each row is its own transaction, oldest first. A row that the run cannot
replay is a skip, and an unexpected error is a failure; neither stops the
run. The run asks for each skipped or failed row only once. The last line
gives four counts:

```
Replayed 120, skipped 3 (not replayable), skipped 1 (already replayed), failed 0 into lane 'orders'.
```

- `skipped (not replayable)`: one line for each row, with the id and the reason.
- `skipped (already replayed)`: another run replayed the row, or a purge
  removed it, after this run read its id.
- `failed`: one line for each row, with the id, the class and the message of
  the error. The exit code is 1 when this count is above 0. A later run
  replays the row.

`--limit <n>` caps the rows that the run handles (replayed, skipped or
failed). `--rate <n>` caps the replays that start in one second. The rate
protects the consumers from a burst after a large bulk replay; the relay
still publishes at its own speed, so the outbox backlog can grow.

`--lane` (default `default`) chooses which outbox lane the replayed
message(s) are re-enqueued into; pick a lane deliberately if you want
replayed traffic isolated from — or merged with — a lane's normal
production.

### Replay to one consumer

A replay goes to the consumer that failed, not to each queue that binds the
routing key. The outbox row of a replay gets the header `x-replay-target`
with the `source` of the dead letter (the work queue). A consumer whose
queue is a different queue acks the delivery with no dispatch, no
deduplication row and no dead letter, and records the event
`message.replay_skipped` (`BrokerEvents::REPLAY_SKIPPED`). The header
survives a retry through the wait queue. `x-replay-target` is reserved: only
the library writes it.

The replay also removes stale headers from the new row: `x-attempt` (the
replay gets the full retry budget), the envelope headers, an old
`x-replay-target`, `x-delivery-count`, `x-death`, `x-first-death-*`,
`x-last-death-*` and `x-dead-letter`. The dead letter row keeps its headers
for triage.

Deploy the release to all consumers before the first replay: a consumer of
an older release ignores the header and processes each replay.

In normal operation each replay gives one `message.replay_skipped` for each
other queue that binds the routing key. A replay with skips and no
processing on any queue shows a target that no consumer serves.

### A replay that no consumer received

After a rename of a queue, each consumer skips a replay whose target is the
old name, and the row is marked replayed. When no queue binds the routing
key, the broker drops the replay. The body stays in `dead_letters` until a
purge, and no command reopens the row. To replay it again:

1. Check `replayed_at` with `dlq:show <id>`.
2. Check that no open row exists for the same message id and source:
   `dlq:list --message-id=<message id> --source=<source> --not-replayed`.
3. Clear the mark with SQL, and replay the row:

```sql
UPDATE dead_letters SET replayed_at = NULL
WHERE id = '<id>'
  AND NOT EXISTS (
      SELECT 1 FROM dead_letters open_row
      WHERE open_row.message_id = dead_letters.message_id
        AND open_row.source = dead_letters.source
        AND open_row.replayed_at IS NULL
  );
```

A replay to a queue with a new name is not possible: the target is always the
`source` of the dead letter.

## Purging

`dlq:purge` deletes the dead letters that match the filter options. It also
takes `--older-than <duration>`, which selects the rows with `failed_at`
before now minus the duration; `--older-than` together with `--until` ends
with exit code 2. With no option, the purge deletes every row:

```bash
# 1. Preview.
php bin/console message-broker:dlq:purge --older-than=30d --dry-run

# 2. Purge for real.
php bin/console message-broker:dlq:purge --older-than=30d --force
```

The dry run and the prompt count with the full filter: the dry run prints
`[dry-run] would delete N dead letters.` and the prompt is
`Delete N dead letters? [y/N]`. The count and the delete are two statements,
so a row that arrives between them can make the deleted count differ from the
shown count.

```bash
# Remove the replayed rows of last month.
php bin/console message-broker:dlq:purge --replayed --until=30d --force
```

**Batch operations fail closed without `--force` on a non-TTY**, exactly
like `dlq:replay --all`: without `--force`, an interactive session is asked
to confirm; a non-interactive session (no TTY) is refused outright rather
than defaulting to "no" silently or "yes" dangerously. Always pass `--force`
in scripted/scheduled purges.

## Importing a dead letter queue of the transport

The consumer writes its dead letters to the table. The transport can also move
a message to its own dead letter queue, with no handler error: for example,
RabbitMQ moves a message of a quorum work queue above its `x-delivery-limit`.
This occurs after stops that the consumer does not control (a killed process,
a lost connection), or after a dead letter write that fails at each delivery.
`message-broker:dlq:import` moves the messages of such a queue into the table,
where the filters, the statistics and the replay apply:

```bash
# For example every 10 minutes from cron.
php bin/console message-broker:dlq:import orders_q.dlq --limit=1000
```

The import stores each message, then acks it. A stop between the two leaves
the message in the queue, and the next run stores it again: one open row
results. When the store fails, the message returns to the queue, and the
command exits with a non-zero code. The transport part (for AMQP, the read of
`x-death`) is in [../amqp/DEAD_LETTERS.md](../amqp/DEAD_LETTERS.md).

The import maps each message to one of three cases:

| Case | `source` | Body | `error_class` | `error_message` | `replayable` |
| --- | --- | --- | --- | --- | --- |
| A move by the transport, the message deserializes | the queue that the transport moved the message from (the work queue) | the canonical document | `Consumer\DeliveryLimitExceeded` for the reason `delivery_limit`; `DeadLetter\MovedByTransport` for each other reason | `Moved by the transport: reason 'delivery_limit', queue 'orders_q', count 1` | `true` |
| A move by the transport, the message does not deserialize | the work queue | the raw bytes | as above | as above, then `; the body did not deserialize: <error>` | `false` |
| No move (for example, a message that a person published into the dead letter queue) | the dead letter queue | the canonical document, else the raw bytes | `DeadLetter\MovedByTransport` | `Moved by the transport: reason 'unknown', queue 'orders_q.dlq'` | `false` |

- `error_trace` is empty, because no handler ran. `attempts` is the attempt of
  the message (`x-attempt` for AMQP), never the delivery count of the
  transport. `failed_at` is the time of the move, else the time of the import.
- The row keeps all headers of the message, also the broker headers
  (`x-death`, `x-first-death-*`, `x-last-death-*`, `x-delivery-count`).
- `DeliveryLimitExceeded` and `MovedByTransport` are synthetic error classes:
  the import uses only their names and never throws them. Select the moved rows
  with `--error`, for example
  `dlq:list --error='Freyr\MessageBroker\DeadLetter\MovedByTransport'`.
- A row of the third case has the dead letter queue as `source`. No consumer
  has that source, so the row is not replayable. Find it with `dlq:list
  --source=orders_q.dlq`, then purge it or publish its message again by hand.
- Each import gives a new row `id`. The sequence "a store, a stop before the
  ack, a replay of that row, the next import run" thus gives a second open row
  for the same message. A second replay of it is absorbed by the deduplication
  of the consumer while its deduplication row exists.

**A message that stops each import run.** The import runs the queue in order.
When the store of one message fails each time (an infrastructure fault that
stays, or a value that the dead letter invariant does not cover), each run
stops at that message, and no later message is imported. The command error
names the cause. Remove that message from the dead letter queue by hand (for
RabbitMQ, with the management UI or `rabbitmqadmin`, after you copied it), and
run the import again.

## Dedup cleanup

Consumer-side deduplication only ever inserts into `message_deduplication` —
nothing else prunes it, so it grows without bound unless you run
`dedup:cleanup` on a schedule (e.g. a daily cron):

```bash
php bin/console message-broker:dedup:cleanup --older-than=7d --dry-run
php bin/console message-broker:dedup:cleanup --older-than=7d
```

Choose `--older-than` comfortably larger than the longest redelivery window
you expect (retry attempt budget plus realistic operational recovery time).
Pruning an entry that a very late redelivery could still reference would
defeat deduplication for that one straggler.
