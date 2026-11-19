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
