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

A duration has one format: `<number><s|m|h|d>`, e.g. `30s`, `15m`, `24h`, `7d`.
`--older-than` takes a duration only. `--since` and `--until` take a duration
or an ISO 8601 date-time with an offset or `Z`.

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
ones. Run it daily (a Kubernetes `CronJob`, see
[Scheduled commands in PRODUCTION.md](PRODUCTION.md#scheduled-commands)). It
must run outside a transaction.

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
process ends with an error, and Kubernetes starts the container again.

**Restart rule.** A limit stop exits with code `0`, so the container must start
again after each exit, exit code `0` included: run the command in a
`Deployment` (`restartPolicy: Always`). See
[Worker limits in PRODUCTION.md](PRODUCTION.md#worker-limits).

## `message-broker:consume <name>`

Runs a registered consumer. Blocks until stopped.

**Constructor:** `new ConsumeCommand(array $consumers)` —
`array<string, callable(WorkerLimits): void>` keyed by consumer name. The
command builds one `Freyr\MessageBroker\Worker\WorkerLimits` from the limit
options and passes it to the callable, which gives it to `AmqpConsumer::run()`:

```php
new ConsumeCommand(['orders' => static fn (WorkerLimits $limits) => $consumer->run($limits)]);
```

A callable with no parameter still runs, but the limits then have no effect;
when a limit option is set, the command prints a warning.

| Argument/Option | Description |
| --- | --- |
| `name` (argument, required) | The registered consumer name. |
| `--limit=<n>` | Stop after `<n>` deliveries (integer, 1 or more). Each delivery that the processor ended counts, whatever its outcome (also a duplicate and a malformed message). The stop is exact. |
| `--time-limit=<seconds>` | Stop after `<seconds>` seconds since the start (integer, 1 or more). The consumer checks the limit after each delivery, and no wait for a delivery is longer than the time that is left, so the stop comes up to one delivery late. |
| `--memory-limit=<size>` | Stop when `memory_get_usage(true)` reaches `<size>`, in the format of the relay option above; 1 or more. Set it below the PHP `memory_limit`. |
| `--failure-limit=<n>` | Stop after `<n>` handler failures in this run (integer, 1 or more): deliveries that the retry policy retried, dead-lettered or discarded. It is a total, not a sequence of consecutive failures. A duplicate, a malformed message and a delivery over the delivery limit do not count. |
| `--require-signals` | Fail immediately at startup if `ext-pcntl` is unavailable (see the relay option above for the effect of a missing extension). |

A limit is checked between deliveries, never inside one: after the processor
committed and acked the delivery, and after a wait with no delivery. A limit
stop is a graceful stop, as SIGTERM is: the consumer logs one `info` line
("Worker limit reached; the worker stops"), returns its unacked deliveries with
no delivery count (see [the return rule](../amqp/CONSUMER.md)) and `run()`
returns. With no limit option, the consumer runs until a signal or a failure, as
before.

Exit codes: `0` after the callable returned, also after a limit stop; `1` with
`--require-signals` and no `ext-pcntl`; `2` for an unknown consumer or an
invalid option value (the callable does not run). A failure of a delivery is
not caught: the process ends with an error, and Kubernetes starts the
container again.

**Restart rule.** A limit stop exits with code `0`, so the container must start
again after each exit, exit code `0` included: run the command in a
`Deployment` (`restartPolicy: Always`). See
[Worker limits in PRODUCTION.md](PRODUCTION.md#worker-limits).

## `message-broker:webhook:setup`

Creates the `webhook_deliveries` table of the webhook delivery queue, with the
daily partitions from today to today plus `--premake-days`. A second run
skips what exists. An application that does not use webhooks does not need
this command. The table is described in
[../postgresql/WEBHOOKS.md](../postgresql/WEBHOOKS.md).

**Constructor:** `new WebhookSetupCommand(SchemaInstaller $installer)`, with
the installer of the webhook table, for example
`new WebhookSetupCommand(new PostgreSqlDeliverySchemaInstaller($pdo))`.

| Option | Description |
| --- | --- |
| `--premake-days` | Create daily partitions from today to today plus this many days (default `3`, `0` creates only today). |

Exit codes: `0` success; `2` invalid `--premake-days` (an integer of 0 or more).

## `message-broker:webhook:maintain`

Retention of `webhook_deliveries`, with the options and the exit codes of
`outbox:maintain`. Creates the upcoming daily partitions and drops the
expired ones. Run it daily (a Kubernetes `CronJob`). It must run outside a
transaction.

**Constructor:** `new WebhookMaintainCommand(DeliveryRetention $retention)`,
for example `new WebhookMaintainCommand(new PostgreSqlDeliveryRetention($pdo))`.

| Option | Description |
| --- | --- |
| `--retention-days` | Keep finished rows at least this many days (default `7`). The cutoff is today midnight UTC minus this value. |
| `--premake-days` | Create daily partitions from today to today plus this many days (default `3`, `0` creates only today). |
| `--dry-run` | Report what would be dropped and what would be kept; change nothing. |

An expired partition that holds an unfinished row is kept and reported. The
command has no option that drops such a partition. A delivery has no time
limit, so the partition stays until each of its rows reaches a final state or
an operator abandons it with `message-broker:webhook:abandon` (below).

Exit codes: `0` success (always on `--dry-run`); `1` at least one expired
partition was kept because of unfinished rows; `2` invalid options
(`--retention-days` must be 1 or more, `--premake-days` 0 or more).

## `message-broker:webhook:run`

Runs the webhook delivery loop (`Webhook\DeliveryLoop`). One worker serves all
lanes, or the lanes of `--lane`. Blocks until stopped. Start the command more
than once to run parallel workers.

**Constructor:** `new WebhookRunCommand(callable $loopFactory, DeliveryLoopConfig $config = new DeliveryLoopConfig())`.
The lane filter, the batch size and the row limit for each lane are fields of
the `DeliveryLoopConfig` that the loop takes in its constructor, so the command
takes a factory `callable(DeliveryLoopConfig): DeliveryLoop` and a base
config. The options replace their fields of the base config; each other field
(lease, lane refresh interval, idle sleep) stays:

```php
use Freyr\MessageBroker\Console\WebhookRunCommand;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;

$application->add(new WebhookRunCommand(
    static fn (DeliveryLoopConfig $config) => new DeliveryLoop(
        new PostgreSqlDeliveryStore($workerPdo),
        $sender,
        $subscriptions,
        config: $config,
        logger: $logger,
        events: $events,
    ),
    new DeliveryLoopConfig(leaseMs: 60_000),
));
```

| Option | Description |
| --- | --- |
| `--lane=<id>` | Claim only this lane (a subscription ID). Repeat it for more lanes: `--lane=crm --lane=erp`. Default: the `laneFilter` of the base config (`null`: all lanes). |
| `--batch-size=<n>` | The most rows of one claim, all lanes together (integer, 1 or more). Default: `batchSize` of the base config. |
| `--lane-limit=<n>` | The most rows of one lane in one claim (integer, 1 or more). Default: `limitForEachLane` of the base config. |
| `--limit=<n>` | Stop after `<n>` delivered rows (integer, 1 or more). The loop checks the limit after each pass, so it can deliver up to one batch above the limit. Retried and dead rows do not count. |
| `--time-limit=<seconds>` | Stop after `<seconds>` seconds since the start (integer, 1 or more), checked after each pass. |
| `--memory-limit=<size>` | Stop when `memory_get_usage(true)` reaches `<size>`, in the format of the relay option above; 1 or more. Set it below the PHP `memory_limit`. |
| `--require-signals` | Fail immediately at startup if `ext-pcntl` is unavailable (see the relay option above for the effect of a missing extension). |

The command builds one `Worker\WorkerLimits` from the three limit options and
gives it to `DeliveryLoop::run()`. A limit stop ends the run like SIGTERM does:
the loop logs one `info` line ("Worker limit reached; the worker stops") and
`run()` returns.

Exit codes: `0` after the loop returned, also after a limit stop; `1` with
`--require-signals` and no `ext-pcntl`; `2` for an invalid option value (no
loop is made). A failed start check of the loop (the lease is not longer than
`maxSendMs()` of the sender) and a database error are not caught: the process
ends with an error, and Kubernetes starts the container again.

**Restart rule.** A limit stop exits with code `0`, so the container must start
again after each exit, exit code `0` included: run the command in a
`Deployment` (`restartPolicy: Always`). See
[Worker limits in PRODUCTION.md](PRODUCTION.md#worker-limits).

## `message-broker:webhook:abandon`

Ends the old unfinished webhook deliveries, by date. A delivery has no time
limit, so this command is the only way to end rows that no rule ends: the
rows of a lane whose endpoint is blocked, and the rows of a subscription that
the application removed. Run it by hand.

**Constructor:** `new WebhookAbandonCommand(DeliveryStore $store, ?BrokerEvents $events = null, LoggerInterface $logger = new NullLogger(), int $batchSize = 1_000)`,
for example `new WebhookAbandonCommand(new PostgreSqlDeliveryStore($pdo), $events, $logger)`.

| Option | Description |
| --- | --- |
| `--before=<date>` | Required. Abandon the rows with a `created_at` earlier than this value: a date (`2026-01-31`, midnight UTC) or an ISO 8601 time (`2026-01-31T12:00:00Z`, `2026-01-31T14:00:00+02:00`). A time with no offset is UTC. |
| `--lane=<id>` | Abandon only the rows of this lane (a subscription ID). Repeat it for more lanes. Default: all lanes, also lanes with no subscription. |
| `--dry-run` | Print the count of each lane; change nothing. |

The command counts the rows of each lane (`DeliveryStore::countUnfinished()`).
Then, for each lane, it calls `DeliveryStore::abandon()` with batches of
`$batchSize` rows until a call finds no row, and prints the count of the lane.
Each abandoned row goes to the dead letters with the error class `abandoned`
and the source `webhook:<lane>`, and gets the final state dead.
`dlq:replay` can send it again to its lane. The command records the event
`webhook.abandoned` (`lane`, `count`) and logs one `warning` line for each
lane.

A leased row is abandoned too. The worker that holds the lease can still send
its request, but its result write changes nothing, because of the lease check.
A row that a different session locks at that moment (a claim or a result
write) is skipped. When the last call of a lane finds no row because only
locked rows are left, the command counts the lane again and prints
`<lane>: <n> row(s) left (locked by a different session); run the command
again.` The exit code stays `0`. Run the command again to end these rows.

```
$ bin/console message-broker:webhook:abandon --before=2026-01-01 --dry-run
[dry-run] Unfinished deliveries created before 2026-01-01 00:00:00.000 UTC:
[dry-run] crm: 120 row(s) would be abandoned.
[dry-run] old-subscription: 3 row(s) would be abandoned.
[dry-run] Total: 123 row(s) would be abandoned.
```

Exit codes: `0` success (also when no row matches, and always on
`--dry-run`); `2` invalid `--before` or an empty `--lane`.

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
the normal relay path. Each replay is one transaction: the replay mark, then
a new outbox row with the original message id, a new `created_at` and the
header `x-replay-target` (the `source` of the dead letter), then the commit.
A webhook dead letter (source `webhook:<lane>`) gets a new delivery row for
that lane instead, and `--lane` has no effect on it; the output names the
webhook lane (see
[Replay of a webhook dead letter](DLQ_OPERATIONS.md#replay-of-a-webhook-dead-letter)).
See [DLQ_OPERATIONS.md](DLQ_OPERATIONS.md) for the worked dry-run-then-force
workflow, the refusals and the replay to one consumer.

**Constructor:** `new DlqReplayCommand(ReplayService $replay, DeadLetterStore $store, int $batchSize = 500)`

A batch replay (`--all`) works in rounds of at most `$batchSize` ids, oldest
first, so memory stays flat regardless of how many dead letters match: the
run keeps only the ids of the rows that it skipped or that failed.

| Argument/Option | Description |
| --- | --- |
| `id` (argument, optional) | A single dead letter id. Omit and pass `--all` for a batch replay. |
| `--all` | Replay every dead letter that matches the filter options, is not replayed and failed before the start of the run (or before `--until` when it is earlier). The command always adds `--not-replayed`; `--replayed` with `--all` ends with exit code 2. The default adds `--replayable`; `--not-replayable` replaces it. |
| The filter options | With `--all`: see [Dead letter filter options](#dead-letter-filter-options). |
| `--limit` | With `--all`: the maximum number of rows that the run handles (replayed, skipped or failed). A positive integer. |
| `--rate` | With `--all`: the maximum number of replays that start in one second. A positive integer; no limit when absent. |
| `--lane` | The outbox lane the replayed message is re-enqueued into (default `default`). No effect on a webhook dead letter. |
| `--dry-run` | Show what would be replayed and change nothing. One id: the checks of the replay ("would replay", or the refusal). `--all`: the count (at most `--limit`) and the first 5 candidates. |
| `--force` | Required to run `--all` non-interactively (no TTY); a single-id replay never prompts. |

The summary line of `--all` gives four counts, for example
`Replayed 120, skipped 3 (not replayable), skipped 1 (already replayed), failed 0 into lane 'orders'.`
The first count is the outbox replays. When the run replayed webhook dead
letters, a second line counts them and names their lanes:
`Replayed 4 webhook dead letter(s) into the webhook lanes of their sources (--lane has no effect on them): crm, erp.`

| Case | Exit code |
| --- | --- |
| One id replayed | 0 |
| One id refused: not found, already replayed, not replayable (one line with the id and the reason) | 1 |
| One id, unexpected error | the exception propagates; non-zero |
| `--all`, no row failed (a skip is not a failure) | 0 |
| `--all`, one or more rows failed with an unexpected error | 1 |
| `--all` not confirmed, or no `--force` without a terminal | 1 |
| A bad option combination or value (`--limit` or `--rate` that is not a positive integer, `--replayed` with `--all`, a bad filter option) | 2 |

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

## `message-broker:dlq:import <name> [--limit <n>]`

Imports the messages of a dead letter queue of the transport into the
`dead_letters` table, and prints `Imported <n> dead letters from '<name>'.`
with the exit code 0. It is a batch command: it ends when the queue is empty
or the limit is reached. Run it from a Kubernetes `CronJob`. The import
cases are in [DLQ_OPERATIONS.md](DLQ_OPERATIONS.md#importing-a-dead-letter-queue-of-the-transport).

**Constructor:** `new DlqImportCommand(array $importers)` —
`array<string, callable(?int $limit): int>` keyed by import name. Each callable
returns the number of imported messages. For AMQP:

```php
use Freyr\MessageBroker\Adapter\Amqp\AmqpDeadLetterImport;
use Freyr\MessageBroker\Console\DlqImportCommand;
use Freyr\MessageBroker\DeadLetter\DeadLetterBuilder;
use Freyr\MessageBroker\DeadLetter\DeadLetterImporter;

$importer = new DeadLetterImporter($deadLetterStore, $deserializer, new DeadLetterBuilder());
$ordersImport = new AmqpDeadLetterImport($channel, 'orders_q.dlq', $importer);

$application->add(new DlqImportCommand([
    'orders_q.dlq' => fn (?int $limit): int => $ordersImport->run($limit),
]));
```

| Argument/Option | Description |
| --- | --- |
| `name` (argument, required) | The registered import name. An unknown name prints the registered names and returns exit code 2 (`INVALID`). |
| `--limit` | The maximum number of messages in this run, a positive integer. Another value returns exit code 2. No option: no limit, the run ends when the queue is empty. |

An error of the callable (for example, a failed store) propagates, and the
command exits with a non-zero code.

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
