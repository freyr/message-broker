# CLI Reference

Every command is a plain Symfony `Console\Command` under the
`Freyr\MessageBroker\Console` namespace, tagged with
`#[AsCommand(name: 'message-broker:...')]`. None are auto-registered — build
each with its listed constructor dependencies and add it to your
application's `Symfony\Component\Console\Application`:

```php
use Freyr\MessageBroker\Console\SetupSchemaCommand;

$application->add(new SetupSchemaCommand($pdo, $platform));
```

Commands are then run through your application's own console entry point
(commonly `bin/console`), e.g. `php bin/console message-broker:setup:schema`.

Duration options (`--since`, `--older-than`) share one format:
`<number><s|m|h|d>`, e.g. `30s`, `15m`, `24h`, `7d`.

## `message-broker:setup:schema`

Creates the `outbox_messages` table (partitioned by `created_at`, with the
first daily partitions and no default partition), `message_deduplication`,
and `dead_letters` (or prints the DDL without executing it). A second run
skips the partitions that are already attached.

**Constructor:** `new SetupSchemaCommand(PDO $pdo, Platform $platform)`

| Option | Description |
| --- | --- |
| `--premake-days` | Create daily outbox partitions from today to today plus this many days (default `3`). |
| `--dump-sql` | Print the DDL instead of executing it, including the partition DDL. The partition DDL is for a new database only. |

## `message-broker:outbox:maintain`

Outbox retention. Creates the upcoming daily partitions and drops the expired
ones. Run it daily (cron). It must run outside a transaction.

**Constructor:** `new OutboxMaintainCommand(OutboxPartitionManager $partitions)`,
for example `new OutboxMaintainCommand(new OutboxPartitionManager($pdo))`.

| Option | Description |
| --- | --- |
| `--retention-days` | Keep rows, published or not, at least this many days (default `7`). The cutoff is today midnight UTC minus this value. |
| `--premake-days` | Create daily partitions from today to today plus this many days (default `3`, `0` creates only today). |
| `--dry-run` | Report what would change; change nothing. |

The command creates each missing partition as a plain table and attaches it
(`ATTACH PARTITION`). It drops each daily partition that lies wholly before the
cutoff: `DETACH PARTITION ... CONCURRENTLY`, then `DROP TABLE`. Neither step
takes an `ACCESS EXCLUSIVE` lock on `outbox_messages`, so producers and relays
never wait for it. The command also finishes a detach that an earlier run did
not complete, and drops an expired table that an earlier run detached.

The retention period (`--retention-days`, default 7) is also the age limit of
an unpublished row. An expired partition is dropped even when it still holds
unpublished rows; the command reports them as a warning. A relay that stays
broken for longer than the retention period loses those messages, so alert on
the age of the oldest unpublished row well before that limit.

Exit codes: `0` success (also when it dropped unpublished rows); `2` invalid
options (`--retention-days` must be 1 or more, `--premake-days` 0 or more).

## `message-broker:relay:run <lane>`

Runs the registered relay for one outbox lane. Blocks until stopped. Start
the command more than once for the same lane to run parallel workers.

**Constructor:** `new RelayRunCommand(array $relays)` —
`array<string, callable(): void>` keyed by lane name.

| Argument/Option | Description |
| --- | --- |
| `lane` (argument, required) | The outbox lane to drain; must be a key in the registered `$relays` array. |
| `--require-signals` | Fail immediately at startup if `ext-pcntl` is unavailable, instead of warning and continuing without graceful shutdown. |

## `message-broker:consume <name>`

Runs a registered consumer. Blocks until stopped.

**Constructor:** `new ConsumeCommand(array $consumers)` —
`array<string, callable(): void>` keyed by consumer name.

| Argument/Option | Description |
| --- | --- |
| `name` (argument, required) | The registered consumer name. |
| `--require-signals` | Fail immediately at startup if `ext-pcntl` is unavailable. |

## `message-broker:dlq:list`

Lists dead letters, newest first.

**Constructor:** `new DlqListCommand(DeadLetterStore $store)`

| Option | Description |
| --- | --- |
| `--name` | Filter by message name. |
| `--source` | Filter by source queue/topic. |
| `--since` | Only failures younger than a duration, e.g. `24h`. |
| `--limit` | Maximum rows (default `100`). |
| `--offset` | Skip this many rows (default `0`), for paging. |

## `message-broker:dlq:show <id>`

Shows one dead letter in full detail: metadata, headers, body, error class,
error message, and stack trace.

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
| `--all` | Replay every non-replayed dead letter matching the filters below. |
| `--name` | With `--all`: only this message name. |
| `--source` | With `--all`: only this source queue/topic. |
| `--since` | With `--all`: only failures younger than a duration, e.g. `24h`. |
| `--lane` | The outbox lane the replayed message is re-enqueued into (default `default`). |
| `--dry-run` | Show what would be replayed (count, and the first 5 candidates for `--all`); change nothing. |
| `--force` | Required to run `--all` non-interactively (no TTY); a single-id replay never prompts. |

## `message-broker:dlq:purge`

Deletes dead letters.

**Constructor:** `new DlqPurgeCommand(DeadLetterStore $store)`

| Option | Description |
| --- | --- |
| `--name` | Only this message name. |
| `--source` | Only this source queue/topic. |
| `--older-than` | Only failures older than a duration, e.g. `30d`. |
| `--dry-run` | Show how many would be deleted; change nothing. The preview counts by `--name`/`--source` only (it does not apply `--older-than`), so it is reported as "up to N" — an upper bound, not an exact count. |
| `--force` | Required to purge non-interactively (no TTY). |

## `message-broker:dedup:cleanup`

Prunes `message_deduplication` entries older than a given age. It deletes in
chunks of 10,000 rows (`PdoDeduplicationStore` constructor argument
`deleteChunkSize`); each chunk is a short statement that the index on
`created_at` serves, so the cleanup holds no long transaction. Keep the
threshold longer than the time in which a duplicate can arrive.

**Constructor:** `new DedupCleanupCommand(DeduplicationStore $store)`

| Option | Description |
| --- | --- |
| `--older-than` | Age threshold, e.g. `7d`, `24h` (default `7d`). |
| `--dry-run` | Report how many entries would be pruned; delete nothing. |
