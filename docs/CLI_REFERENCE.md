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

Creates the `outbox_messages`, `message_deduplication`, and `dead_letters`
tables (or prints the DDL without executing it).

**Constructor:** `new SetupSchemaCommand(PDO $pdo, Platform $platform)`

| Option | Description |
| --- | --- |
| `--format` | `json` or `avro` (default `json`). Selects the outbox `body` column type and the rest of the DDL. Must match the `WireFormat` your producer uses. |
| `--dump-sql` | Print the DDL instead of executing it. |

## `message-broker:relay:run <lane>`

Runs the registered relay for one outbox lane. Blocks until stopped.

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

## `message-broker:schema:register`

Registers mapped Avro schemas with the schema registry — an out-of-band CI
step; the runtime never registers.

**Constructor:** `new SchemaRegisterCommand(FileSchemaStore $schemas, SchemaRegistrar $registrar)`

| Option | Description |
| --- | --- |
| `--subject` | Register only this subject (message name); default registers every subject mapped in `$schemas`. |
| `--compatibility` | Pin the subject's compatibility level before registering (e.g. `FULL`, `BACKWARD`). Governs the subject before its first version, so the first schema is checked too. |
| `--dry-run` | List the subjects that would be registered without writing anything. |

## `message-broker:schema:compatibility`

Sets or shows a subject's registry compatibility level, independent of
registering a schema version. See [SCHEMA_AVRO.md](SCHEMA_AVRO.md) for the
valid levels and the `IncompatibleSchema`/`RegistryUnavailable` error split.

**Constructor:** `new SchemaCompatibilityCommand(SchemaRegistrar $registrar)`

| Option | Description |
| --- | --- |
| `--subject` | Required. The subject (message name) to govern. |
| `--level` | Level to set. Omit to print the current level (or `(registry default)` if no per-subject override exists). |

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
the normal relay path. See [DLQ_OPERATIONS.md](DLQ_OPERATIONS.md) for the
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

Prunes `message_deduplication` entries older than a given age.

**Constructor:** `new DedupCleanupCommand(DeduplicationStore $store)`

| Option | Description |
| --- | --- |
| `--older-than` | Age threshold, e.g. `7d`, `24h` (default `7d`). |
| `--dry-run` | Report how many entries would be pruned; delete nothing. |
