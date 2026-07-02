# DLQ Operations

Consumption failures that exhaust their retry budget land in the shared
`dead_letters` table — the same table regardless of transport. This document
covers the operator-facing commands for inspecting, replaying, purging, and
housekeeping the DLQ. See [CLI_REFERENCE.md](CLI_REFERENCE.md) for the full
option list of each command.

## Inspecting

`dlq:list` lists dead letters newest-first, with `--name`, `--source`,
`--since`, `--limit`, and `--offset` filters/paging. `dlq:show <id>` prints
one dead letter in full — metadata, headers, body, error class/message, and
stack trace.

```bash
php bin/console message-broker:dlq:list --source=orders_q --since=24h --limit=20
php bin/console message-broker:dlq:show 0190f7e2-1234-7000-8000-abc123456789
```

## Replaying

`dlq:replay` re-enqueues a dead letter back into the outbox under a chosen
lane, so redelivery rides the normal relay path (publisher confirms,
ordering). The dead letter row is kept for audit and marked `replayed_at`
rather than deleted.

**Single id** replays immediately, with no confirmation prompt:

```bash
php bin/console message-broker:dlq:replay 0190f7e2-1234-7000-8000-abc123456789 --lane=orders
```

**`--all`** replays every non-replayed dead letter matching `--name`,
`--source`, and `--since`. This is a batch operation, so it always goes
through the dry-run-then-force workflow:

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

`dlq:purge` deletes dead letters, filtered by `--name`, `--source`, and
`--older-than`:

```bash
# 1. Preview.
php bin/console message-broker:dlq:purge --older-than=30d --dry-run

# 2. Purge for real.
php bin/console message-broker:dlq:purge --older-than=30d --force
```

**The dry-run preview is an upper bound, not an exact count.** It reports
"would delete up to N dead letters" — the count applies `--name`/`--source`
but **ignores** `--older-than`; the actual delete additionally applies
`--older-than` and can remove fewer rows than the preview showed.

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
