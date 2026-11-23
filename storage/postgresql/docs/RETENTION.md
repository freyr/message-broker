# Outbox retention

This document describes how the PostgreSQL adapter creates and drops the
partitions of `outbox_messages`. The table is described in
[STORAGE.md](STORAGE.md). The command `message-broker:outbox:maintain` is
a core console command; its options and exit codes are in
[docs/CLI_REFERENCE.md](https://github.com/freyr/message-broker/blob/main/docs/CLI_REFERENCE.md). The class that does the
work is `PostgreSqlOutboxRetention`, which implements the core port
`OutboxRetention`. The retention of `webhook_deliveries` has the same rules,
with no option to drop a partition that holds an unfinished row; it is in
[WEBHOOKS.md](WEBHOOKS.md).

With the default `PublishedRows::Mark`, the relay never deletes rows. It sets `published_at`, and the rows stay until
retention drops their partition, once every row of it is published or abandoned. With `PublishedRows::Delete`, the
relay deletes each published row, and an expired partition holds only unpublished or abandoned rows, or none; the
command is still needed for the partitions ahead and the drop (see
[STORAGE.md](STORAGE.md#published-rows-mark-or-delete)). `outbox_messages` is partitioned by
`created_at`, with one partition per UTC day (`outbox_messages_pYYYYMMDD`) and
no default partition. An insert for a day with no partition fails, and the
producer's transaction fails with it, so create partitions ahead of time.

Run `message-broker:outbox:maintain` once a day (a Kubernetes `CronJob`, see
[Scheduled commands](https://github.com/freyr/message-broker/blob/main/docs/PRODUCTION.md#scheduled-commands)):

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

**Unpublished rows.** By default, an expired partition that still holds a row
with `published_at IS NULL AND abandoned_at IS NULL` is kept. The command prints the partition and the
number of unpublished rows and exits with code 1, so that the `Job` of the
`CronJob` fails and a monitor alerts. A later run drops the partition once its rows are published.
The same rule covers a partition with a pending detach and an expired table
that an earlier run detached. A partition that is kept grows, so fix the relay
or alert on the exit code well before the disk fills.

**Abandoned rows.** An abandoned row (`abandoned_at IS NOT NULL`, see
`message-broker:outbox:abandon`) does not keep its partition. An expired
partition whose only unpublished rows are abandoned is dropped, and the
command prints one line for it, for example `outbox_messages_p20260301 held 2
abandoned row(s); they are dropped.`. The line does not change the exit code.
A partition with abandoned rows and other unpublished rows is kept; the count
of the kept line is the count of the other rows only. With
`--drop-unpublished`, the command reports the unpublished rows and the
abandoned rows of a dropped partition apart. A dry run reports the abandoned
rows that it would drop. In code, `RetentionReport::$abandoned` holds the
count for each partition, and `abandonedRowsDropped()` gives the sum.

The check and the detach are not one atomic step. The command counts the
unpublished rows before the detach and again on the detached table, before
`DROP TABLE`. No row can reach the table after the detach, so the second count
catches a row that arrived in between. The command then attaches the table
again (`ATTACH PARTITION`, which takes only `SHARE UPDATE EXCLUSIVE`), so the
relay claims and publishes its rows, and a later run drops it. The same
applies to an expired table that an earlier run detached, and to a partition
with a pending detach (the command finishes the detach first, then attaches
it). If the attach fails, the command stops with the error, drops nothing, and
the table stays detached; fix the cause and run it again. Nothing is locked.

**Hard age limit.** The option `--drop-unpublished` restores the hard age
limit: the retention period is then also the age limit of an unpublished row.
The command drops an expired partition with its unpublished rows, prints a
warning with the number of rows, and exits with code 0. A relay that stays
broken for longer than the retention period loses those messages.

**Locks.** No step takes an `ACCESS EXCLUSIVE` lock on `outbox_messages`.
`ATTACH PARTITION` and `DETACH PARTITION ... CONCURRENTLY` take only
`SHARE UPDATE EXCLUSIVE`, which does not block inserts, claims or updates. The
detach waits until the transactions that use `outbox_messages` end, so a long
business transaction delays the command but blocks nobody. The command must
run outside a transaction. A plain `DROP TABLE` or `DETACH PARTITION` of a
partition takes `ACCESS EXCLUSIVE` on the parent and queues every producer
behind it; do not run them on this table by hand.

It exits with code 0 on success (also when it dropped unpublished rows with
`--drop-unpublished`), with code 1 when it kept at least one expired partition
because of unpublished rows, and with code 2 on invalid options. Use
`--dry-run` to preview the changes; a dry run always exits with code 0.

## With pg_partman

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

pg_partman takes the columns of a new partition from the parent; its template
table manages only the properties that its documentation lists (for example a
primary key on a column that is not the partition key). A new column such as
`abandoned_at` needs no change of the pg_partman configuration.

Monitor three signals: the age of the oldest unpublished row per lane, the
exit code of the maintain command, and the partition of tomorrow.
