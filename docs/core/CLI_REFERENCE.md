# CLI Reference

Every command is a plain Symfony `Console\Command` under the
`Freyr\MessageBroker\Console` namespace, tagged with
`#[AsCommand(name: 'message-broker:...')]`. None are auto-registered — build
each with its listed constructor dependencies and add it to your
application's `Symfony\Component\Console\Application`:

```php
use Freyr\MessageBroker\Console\SetupSchemaCommand;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlSchemaInstaller;

$application->add(new SetupSchemaCommand(new PostgreSqlSchemaInstaller($pdo)));
```

Commands are then run through your application's own console entry point
(commonly `bin/console`), e.g. `php bin/console message-broker:setup:schema`.

Duration options (`--since`, `--older-than`) share one format:
`<number><s|m|h|d>`, e.g. `30s`, `15m`, `24h`, `7d`.

## `message-broker:setup:schema`

Creates the `outbox_messages` table (partitioned by `created_at`, with the
first daily partitions and no default partition), `message_deduplication`,
and `dead_letters` (or prints the DDL without executing it). A second run
skips the partitions that are already attached. The tables and the installer
are described in [../postgresql/STORAGE.md](../postgresql/STORAGE.md).

**Constructor:** `new SetupSchemaCommand(SchemaInstaller $installer)`, for example
`new SetupSchemaCommand(new PostgreSqlSchemaInstaller($pdo))`. The `SchemaInstaller` port
has `sql(int $daysAhead)` (the DDL of a new database) and `install(int $daysAhead)`
(creates what is missing; safe to repeat).

| Option | Description |
| --- | --- |
| `--premake-days` | Create daily outbox partitions from today to today plus this many days (default `3`). |
| `--dump-sql` | Print the DDL instead of executing it, including the partition DDL. The partition DDL is for a new database only. |

## `message-broker:outbox:maintain`

Outbox retention. Creates the upcoming daily partitions and drops the expired
ones. Run it daily (cron). It must run outside a transaction.

**Constructor:** `new OutboxMaintainCommand(OutboxRetention $retention)`,
for example `new OutboxMaintainCommand(new PostgreSqlOutboxRetention($pdo))`.

| Option | Description |
| --- | --- |
| `--retention-days` | Keep rows at least this many days (default `7`). The cutoff is today midnight UTC minus this value. An expired partition with unpublished rows is kept unless `--drop-unpublished` is set. |
| `--premake-days` | Create daily partitions from today to today plus this many days (default `3`, `0` creates only today). |
| `--drop-unpublished` | Drop an expired partition even when it holds unpublished rows (data loss). Default: keep it. |
| `--dry-run` | Report what would be dropped and what would be kept; change nothing. |

The partitions, the retention rules and the locks are described in
[../postgresql/RETENTION.md](../postgresql/RETENTION.md).

An abandoned row (see `message-broker:outbox:abandon`) does not keep its
partition. For each dropped partition that held abandoned rows, the command
prints one line, for example `outbox_messages_p20260301 held 2 abandoned
row(s); they are dropped.` (with the prefix `[dry-run] ` on a dry run). These
lines do not change the exit code.

Exit codes: `0` success (also when it dropped unpublished rows with
`--drop-unpublished`, and always on `--dry-run`); `1` at least one expired
partition was kept because of unpublished rows; `2` invalid options
(`--retention-days` must be 1 or more, `--premake-days` 0 or more).

## `message-broker:outbox:failing`

Lists the open outbox rows of one lane that have failed publish attempts:
rows that are not published and not abandoned, with `attempts` of at least
`--min-attempts`. The list includes rows that are not due yet. The rows with
the most attempts come first, then the oldest rows. Use it to find a row that
the transport refuses again and again (see [RELAY.md](RELAY.md), *A row that
the transport refuses*).

**Constructor:** `new OutboxFailingCommand(OutboxStore $store)`, for example
`new OutboxFailingCommand(new PostgreSqlOutboxStore($pdo))`.

| Option | Description |
| --- | --- |
| `--lane` | The outbox lane (default `default`, the default lane of `OutboxProducer` and `Relay`). |
| `--min-attempts` | Only rows with at least this many failed attempts (default `3`). |
| `--limit` | Maximum rows (default `100`). |

The output is a table with the columns `id`, `lane`, `message_name`,
`attempts`, `created_at`, `next_attempt_at` and `body_bytes` (times in UTC).
The command shows the size of the body, never the body, because the body can
hold personal data. With no row, it prints one line that says so.

Exit codes: `0` success (also when it lists rows); `2` invalid options
(`--min-attempts` and `--limit` must be integers of 1 or more).

## `message-broker:outbox:abandon <id>`

Abandons the open outbox rows of one message ID: the rows that are not
published and not abandoned get `abandoned_at`. The relay never claims an
abandoned row again, and retention drops it with its partition. A replay
makes a second row with the same ID, so the count can be more than 1. The
command asks no confirmation: SQL can undo the mark while the partition exists
(see [RELAY.md](RELAY.md)). Read the broker error before you abandon a row.

**Constructor:** `new OutboxAbandonCommand(OutboxStore $store, ?BrokerEvents $events = null, LoggerInterface $logger = new NullLogger())`.

| Argument/Option | Description |
| --- | --- |
| `id` (argument, required) | The message ID of the outbox row. |
| `--reason` | Why the row is abandoned. It goes to the log and to the event, not to the row. |

When it marks a row, the command logs the warning "Outbox row abandoned by an
operator; it will not be published" and records the event `message.abandoned`,
both with `message_id`, `count` and `reason` (`null` with no `--reason`), and
prints the count.

Exit codes: `0` one or more rows abandoned; `1` no row abandoned ("No
unpublished row with this ID: it is published, already abandoned, or does not
exist."), with no event and no log; `2` the ID is empty.

## `message-broker:relay:run <lane>`

Runs the registered relay for one outbox lane. Blocks until stopped. Start
the command more than once for the same lane to run parallel workers.

**Constructor:** `new RelayRunCommand(array $relays)` —
`array<string, callable(WorkerLimits): void>` keyed by lane name. The command
builds one `Freyr\MessageBroker\Worker\WorkerLimits` from the limit options and
passes it to the callable, which gives it to `Relay::run()`:

```php
new RelayRunCommand(['orders' => static fn (WorkerLimits $limits) => $relay->run($limits)]);
```

A callable with no parameter still runs, but the limits then have no effect;
when a limit option is set, the command prints a warning.

| Argument/Option | Description |
| --- | --- |
| `lane` (argument, required) | The outbox lane to drain; must be a key in the registered `$relays` array. |
| `--limit=<n>` | Stop after `<n>` published rows (integer, 1 or more). The relay checks the limit after each batch, so it can publish up to one batch (`batchSize`) above the limit. Rows that back off do not count. |
| `--time-limit=<seconds>` | Stop after `<seconds>` seconds since the start (integer, 1 or more). The relay checks the limit after each pass, so the stop comes up to one batch and one idle sleep late. |
| `--memory-limit=<size>` | Stop when `memory_get_usage(true)` reaches `<size>`: `<number>[K\|M\|G]`, case-insensitive, binary units (`128M` is 134217728 bytes); a plain number is bytes; 1 or more. Set it below the PHP `memory_limit`. |
| `--require-signals` | Fail immediately at startup if `ext-pcntl` is unavailable, instead of warning and continuing without graceful shutdown (without `ext-pcntl`, SIGTERM ends the process at once, or is ignored until SIGKILL when the process is PID 1 of a container). |

A limit is checked between passes, never inside one. A limit stop ends the run
like SIGTERM does: the relay logs one `info` line ("Worker limit reached; the
worker stops") and `run()` returns. With no limit option, the relay runs until
a signal or a failure, as before.

Exit codes: `0` after the callable returned, also after a limit stop; `1` with
`--require-signals` and no `ext-pcntl`; `2` for an unknown lane or an invalid
option value (the callable does not run). A publisher failure is not caught: the
process ends with an error, and the supervisor restarts it.

**Supervisor rule.** A limit stop exits with code `0`, so the supervisor must
start a new process after each exit, exit code `0` included. See
[Worker limits in PRODUCTION.md](PRODUCTION.md#worker-limits).

## `message-broker:consume <name>`

Runs a registered consumer. Blocks until stopped.

**Constructor:** `new ConsumeCommand(array $consumers)` —
`array<string, callable(): void>` keyed by consumer name.

| Argument/Option | Description |
| --- | --- |
| `name` (argument, required) | The registered consumer name. |
| `--require-signals` | Fail immediately at startup if `ext-pcntl` is unavailable (see the relay option above for the effect of a missing extension). |

## Dead letter filter options

`dlq:list`, `dlq:stats`, `dlq:purge` and `dlq:replay --all` take these
options (`Console\DeadLetterFilterOptions`). All set options combine with
AND. An invalid value, or both flags of one pair, ends the command with exit
code 2 (`INVALID`) before it reads the table.

| Option | Description |
| --- | --- |
| `--name` | Only this message name. |
| `--source` | Only this source (work queue). |
| `--error` | Only this error class (the full class name; exact match). |
| `--message-id` | Only this message ID. |
| `--since` | Only failures at or after a time: a duration (`24h`: now minus the duration) or an ISO 8601 date-time with an offset or `Z`. |
| `--until` | Only failures before a time (exclusive); the same forms as `--since`. |
| `--min-attempts` | Only rows with at least this many attempts (an integer of 1 or more). |
| `--replayed` / `--not-replayed` | Only replayed / only not replayed rows; not both. |
| `--replayable` / `--not-replayable` | Only rows with `replayable` true / false; not both. |

## `message-broker:dlq:list`

Lists dead letters, newest first. The default shows replayed and open rows.
The columns are `id`, `message_name`, `source`, `attempts`, `failed_at`,
`replayed`, `replayable` (`yes` / `no`) and `error`.

**Constructor:** `new DlqListCommand(DeadLetterStore $store)`

| Option | Description |
| --- | --- |
| The filter options | See [Dead letter filter options](#dead-letter-filter-options). |
| `--limit` | Maximum rows (default `100`). |
| `--offset` | Skip this many rows (default `0`), for paging. |

## `message-broker:dlq:show <id>`

Shows one dead letter in full detail: metadata, the replay time, the
`replayable` mark (`yes` / `no`), headers, body, error class, error message,
and stack trace.

**Constructor:** `new DlqShowCommand(DeadLetterStore $store)`

| Argument | Description |
| --- | --- |
| `id` (required) | The dead letter's id. |

## `message-broker:dlq:replay [<id>] [--all]`

Re-enqueues one or many dead letters back into the outbox; redelivery rides
the normal relay path. The command inserts a new outbox row with the original
message id and a new `created_at`. See [DLQ_OPERATIONS.md](DLQ_OPERATIONS.md) for the
worked dry-run-then-force workflow.

**Constructor:** `new DlqReplayCommand(ReplayService $replay, DeadLetterStore $store, int $batchSize = 500)`

A batch replay (`--all`) drains the DLQ `$batchSize` rows at a time, so memory
stays flat regardless of how many dead letters match.

| Argument/Option | Description |
| --- | --- |
| `id` (argument, optional) | A single dead letter id. Omit and pass `--all` for a batch replay. |
| `--all` | Replay every dead letter that matches the filter options and is not replayed. The command always adds `--not-replayed`; `--replayed` with `--all` ends with exit code 2. |
| The filter options | With `--all`: see [Dead letter filter options](#dead-letter-filter-options). |
| `--lane` | The outbox lane the replayed message is re-enqueued into (default `default`). |
| `--dry-run` | Show what would be replayed (count, and the first 5 candidates for `--all`); change nothing. |
| `--force` | Required to run `--all` non-interactively (no TTY); a single-id replay never prompts. |

## `message-broker:dlq:purge`

Deletes dead letters.

**Constructor:** `new DlqPurgeCommand(DeadLetterStore $store)`

With no option it deletes every row. The dry run and the prompt count with
the full filter.

| Option | Description |
| --- | --- |
| The filter options | See [Dead letter filter options](#dead-letter-filter-options). |
| `--older-than` | Only failures older than a duration, e.g. `30d` (sets the upper bound of `failed_at`). Not with `--until` (exit code 2). |
| `--dry-run` | Print `[dry-run] would delete N dead letters.` with the exact count; change nothing. |
| `--force` | Required to purge non-interactively (no TTY). Without it, an interactive session gets the prompt `Delete N dead letters? [y/N]`. |

## `message-broker:dlq:stats`

Prints one table row for each group of message name, source and error class
of the matching rows: `message_name`, `source`, `error_class`, `count`,
`oldest_failed_at` and `newest_failed_at`, the largest group first. With no
group it prints `No dead letters match.` The exit code is 0. It prints no
body and no error message.

**Constructor:** `new DlqStatsCommand(DeadLetterStore $store)`

| Option | Description |
| --- | --- |
| The filter options | See [Dead letter filter options](#dead-letter-filter-options). With no option it groups all rows; `--not-replayed` gives the open rows. |

## `message-broker:dedup:cleanup`

Prunes `message_deduplication` entries older than a given age. Keep the
threshold longer than the time in which a duplicate can arrive. The chunked
delete of the PostgreSQL store is described in
[../postgresql/STORAGE.md](../postgresql/STORAGE.md).

**Constructor:** `new DedupCleanupCommand(DeduplicationStore $store)`

| Option | Description |
| --- | --- |
| `--older-than` | Age threshold, e.g. `7d`, `24h` (default `7d`). |
| `--dry-run` | Report how many entries would be pruned; delete nothing. |
