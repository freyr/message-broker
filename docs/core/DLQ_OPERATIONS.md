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

**Single id** replays immediately, with no confirmation prompt:

```bash
php bin/console message-broker:dlq:replay 0190f7e2-1234-7000-8000-abc123456789 --lane=orders
```

**`--all`** replays every dead letter that matches the filter options and
is not replayed: the command always adds the condition `--not-replayed`.
`--replayed` together with `--all` ends with exit code 2 and replays nothing.
This is a batch operation, so it always goes through the dry-run-then-force
workflow:

```bash
# 1. See what would happen — count plus a preview of the first 5 candidates.
php bin/console message-broker:dlq:replay --all --source=orders_q --since=24h --dry-run

# 2. Run it for real.
php bin/console message-broker:dlq:replay --all --source=orders_q --since=24h --force
```

Without `--force`, a `--all` replay asks for interactive confirmation
(`[y/N]`). If standard input is not a TTY (a cron job, a CI step, a
non-interactive shell), the command **refuses to run** rather than silently
proceeding — pass `--force` explicitly for any non-interactive batch replay.

`--lane` (default `default`) chooses which outbox lane the replayed
message(s) are re-enqueued into; pick a lane deliberately if you want
replayed traffic isolated from — or merged with — a lane's normal
production.

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
