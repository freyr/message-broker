<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Outbox\OutboxRetention;
use Freyr\MessageBroker\Outbox\RetentionReport;
use LogicException;
use PDO;
use RuntimeException;
use Symfony\Component\Clock\Clock;

/**
 * PostgreSQL implementation of OutboxRetention: partitions and retention of
 * the outbox_messages table. With PublishedRows::Mark (the default) the relay never deletes rows; they leave the
 * table here, one daily partition at a time. With PublishedRows::Delete the expired partitions hold only
 * unpublished or abandoned rows, or none.
 *
 *  - ensureCapacity() creates one range partition per UTC day
 *    (outbox_messages_pYYYYMMDD, [day, day + 1)) from today to today + N days.
 *    It creates a plain table and ATTACHes it, which takes only a SHARE UPDATE
 *    EXCLUSIVE lock on outbox_messages, so producers and relays never wait.
 *    The name matches the pg_partman default for daily partitions, so
 *    pg_partman can create the partitions instead.
 *  - purge() drops each daily partition that lies wholly before the retention
 *    cutoff: DETACH PARTITION ... CONCURRENTLY (no ACCESS EXCLUSIVE lock on
 *    outbox_messages), then DROP TABLE on the detached table. By default an
 *    expired partition that still holds unpublished rows (published_at IS
 *    NULL AND abandoned_at IS NULL) is kept and reported, so retention never
 *    loses a committed message;
 *    it is dropped on a later run, once its rows are published. An abandoned
 *    row does not keep its partition; the report counts the abandoned rows of
 *    each partition that is dropped. With
 *    $dropUnpublished the retention period is a hard age limit: the partition
 *    is dropped with its unpublished rows, and the report counts them.
 *
 * Safety of the default mode. The check and the detach are not one atomic
 * step, so purge() counts the unpublished rows twice: before the detach, and
 * again on the detached table, right before DROP TABLE. After DETACH ...
 * CONCURRENTLY returns, no new row can reach the table, and every row that
 * committed before the detach is visible to the second count. A row that
 * appeared between the first count and the detach is therefore found.
 *
 * A detached table that holds unpublished rows is attached again at once
 * (ALTER TABLE ... ATTACH PARTITION with the bounds of its day), because the
 * relay claims rows through outbox_messages and could not publish them
 * otherwise. The table is then reported as kept, and a later run drops it once
 * its rows are published. The same rule covers an expired table that an
 * earlier run detached but did not drop, and a partition with a pending
 * detach (it is finalized first, because only a fully detached table can be
 * attached again). ATTACH takes only SHARE UPDATE EXCLUSIVE on
 * outbox_messages, as ensureCapacity() does, so producers and relays do not
 * wait. DETACH ... CONCURRENTLY leaves a CHECK constraint equal to the
 * partition constraint on the table; ATTACH uses it to skip the validation
 * scan, and a later detach of the same table works with it in place. If the
 * attach itself fails, the exception propagates: the table stays detached,
 * nothing is dropped, and the caller sees the error. Nothing else is locked.
 *
 * There is no DEFAULT partition (DETACH ... CONCURRENTLY does not allow one):
 * an insert for a day with no partition fails. Create partitions ahead of time.
 */
final readonly class PostgreSqlOutboxRetention implements OutboxRetention
{
    public const string TABLE = 'outbox_messages';
    private const string DAILY_PREFIX = 'outbox_messages_p';

    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * DDL that creates and attaches the daily partitions from $today to
     * $today + $daysAhead (inclusive). For a schema with no partition yet.
     * $today (default: the current UTC day) is a test parameter of this class,
     * not part of the OutboxRetention port.
     *
     * @return list<string>
     */
    public function partitionDdl(int $daysAhead, ?DateTimeImmutable $today = null): array
    {
        self::assertNotNegative('daysAhead', $daysAhead);
        $day = self::utcMidnight($today);

        $ddl = [];
        for ($offset = 0; $offset <= $daysAhead; ++$offset) {
            array_push($ddl, ...self::createAndAttach($day->modify("+{$offset} day")));
        }

        return $ddl;
    }

    /**
     * Create the missing daily partitions from today to today + $daysAhead.
     * A partition that is already attached (made by this class or by
     * pg_partman) is skipped.
     *
     * $today (default: the current UTC day) is a test parameter of this class,
     * not part of the OutboxRetention port.
     *
     * @return list<string> the daily partition names that now exist for that range
     */
    public function ensureCapacity(int $daysAhead, ?DateTimeImmutable $today = null): array
    {
        self::assertNotNegative('daysAhead', $daysAhead);
        $day = self::utcMidnight($today);
        $attached = array_keys(array_filter(
            $this->dailyTables(),
            static fn (array $table): bool => $table['attached'],
        ));

        $names = [];
        for ($offset = 0; $offset <= $daysAhead; ++$offset) {
            $from = $day->modify("+{$offset} day");
            $name = self::partitionName($from);
            if (!in_array($name, $attached, true)) {
                foreach (self::createAndAttach($from) as $ddl) {
                    $this->pdo->exec($ddl);
                }
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Drop every daily partition that lies wholly before the cutoff (today
     * 00:00 UTC minus $retentionDays). A partition that holds unpublished rows
     * (not abandoned) is kept and reported in RetentionReport::$kept, unless
     * $dropUnpublished is true; then it is dropped with them and reported in
     * RetentionReport::$unpublished. The abandoned rows of a partition that is
     * dropped (or would be) go to RetentionReport::$abandoned. Also finishes a detach that an
     * earlier run did not complete, and drops an expired daily table that an
     * earlier run detached but did not drop (the same rule applies to both).
     *
     * Must run outside a transaction: DETACH ... CONCURRENTLY cannot run in
     * a transaction block. $today (default: the current UTC day) is a test
     * parameter of this class, not part of the OutboxRetention port.
     */
    public function purge(
        int $retentionDays,
        bool $dryRun = false,
        bool $dropUnpublished = false,
        ?DateTimeImmutable $today = null,
    ): RetentionReport {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException("retentionDays must be 1 or more, got {$retentionDays}");
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Outbox purge cannot run inside a transaction (DETACH PARTITION CONCURRENTLY).');
        }
        $cutoff = self::utcMidnight($today)->modify("-{$retentionDays} day");

        $dropped = [];
        $unpublished = [];
        $kept = [];
        $abandoned = [];
        foreach ($this->dailyTables() as $name => $table) {
            if ($table['day']->modify('+1 day') > $cutoff) {
                continue; // not wholly expired yet
            }

            $pending = $this->countUnpublished($name);
            if ($pending > 0 && !$dropUnpublished) {
                if (!$dryRun && (!$table['attached'] || $table['detachPending'])) {
                    if ($table['detachPending']) {
                        $this->pdo->exec(sprintf('ALTER TABLE %s DETACH PARTITION "%s" FINALIZE', self::TABLE, $name));
                    }
                    $this->attach($name, $table['day']);
                }
                $kept[$name] = $pending;
                continue;
            }
            if ($pending > 0) {
                $unpublished[$name] = $pending;
            }

            if (!$dryRun) {
                if ($table['detachPending']) {
                    $this->pdo->exec(sprintf('ALTER TABLE %s DETACH PARTITION "%s" FINALIZE', self::TABLE, $name));
                } elseif ($table['attached']) {
                    $this->pdo->exec(sprintf('ALTER TABLE %s DETACH PARTITION "%s" CONCURRENTLY', self::TABLE, $name));
                }
                // The table is detached now, so nothing can add a row. Count again:
                // a row may have arrived between the first count and the detach.
                if (!$dropUnpublished) {
                    $late = $this->countUnpublished($name);
                    if ($late > 0) {
                        $this->attach($name, $table['day']);
                        $kept[$name] = $late;
                        continue;
                    }
                }
            }
            // Detached (or, on a dry run, still attached): count the abandoned
            // rows that go with the table.
            $abandonedRows = $this->countRows($name, 'published_at IS NULL AND abandoned_at IS NOT NULL');
            if ($abandonedRows > 0) {
                $abandoned[$name] = $abandonedRows;
            }
            if (!$dryRun) {
                $this->pdo->exec("DROP TABLE \"{$name}\"");
            }
            $dropped[] = $name;
        }

        return new RetentionReport($cutoff, $dropped, $unpublished, $kept, $abandoned);
    }

    private function attach(string $name, DateTimeImmutable $from): void
    {
        $this->pdo->exec(self::attachDdl($name, $from));
    }

    private static function attachDdl(string $name, DateTimeImmutable $from): string
    {
        return sprintf(
            "ALTER TABLE %s ATTACH PARTITION \"%s\" FOR VALUES FROM ('%s') TO ('%s')",
            self::TABLE,
            $name,
            $from->format('Y-m-d H:i:s'),
            $from->modify('+1 day')->format('Y-m-d H:i:s'),
        );
    }

    /** @return list<string> */
    private static function createAndAttach(DateTimeImmutable $from): array
    {
        $name = self::partitionName($from);

        return [
            sprintf(
                'CREATE TABLE IF NOT EXISTS "%s" (LIKE %s INCLUDING DEFAULTS INCLUDING CONSTRAINTS)',
                $name,
                self::TABLE,
            ),
            self::attachDdl($name, $from),
        ];
    }

    /**
     * The daily tables in the schema of outbox_messages, oldest first: its
     * attached partitions, a partition with an unfinished detach, and a
     * detached table that was not dropped. Names come from the catalog and
     * must match the daily naming pattern, so they are safe to interpolate
     * into DDL.
     *
     * @return array<string, array{day: DateTimeImmutable, attached: bool, detachPending: bool}>
     */
    private function dailyTables(): array
    {
        $statement = $this->pdo->query(
            "SELECT c.relname, i.inhrelid IS NOT NULL AS attached, COALESCE(i.inhdetachpending, false) AS detach_pending
               FROM pg_class c
               LEFT JOIN pg_inherits i ON i.inhrelid = c.oid
              WHERE c.relkind = 'r'
                AND c.relnamespace = (SELECT relnamespace FROM pg_class WHERE oid = '".self::TABLE."'::regclass)
                AND c.relname ~ '^".self::DAILY_PREFIX."[0-9]{8}$'
                AND (i.inhparent IS NULL OR i.inhparent = '".self::TABLE."'::regclass)
              ORDER BY c.relname",
        );
        if ($statement === false) {
            throw new RuntimeException('Cannot list the outbox partitions');
        }

        $tables = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_string($row['relname'] ?? null)) {
                continue;
            }
            $name = $row['relname'];
            $day = DateTimeImmutable::createFromFormat('!Ymd', substr($name, strlen(self::DAILY_PREFIX)), new DateTimeZone('UTC'));
            if ($day === false) {
                continue;
            }
            $tables[$name] = [
                'day' => $day,
                'attached' => $row['attached'] === true,
                'detachPending' => $row['detach_pending'] === true,
            ];
        }

        return $tables;
    }

    private function countUnpublished(string $table): int
    {
        return $this->countRows($table, 'published_at IS NULL AND abandoned_at IS NULL');
    }

    private function countRows(string $table, string $condition): int
    {
        $statement = $this->pdo->query("SELECT COUNT(*) FROM \"{$table}\" WHERE {$condition}");
        $value = $statement === false ? false : $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function partitionName(DateTimeImmutable $day): string
    {
        return self::DAILY_PREFIX.$day->format('Ymd');
    }

    private static function utcMidnight(?DateTimeImmutable $today): DateTimeImmutable
    {
        return ($today ?? Clock::get()->now())
            ->setTimezone(new DateTimeZone('UTC'))
            ->setTime(0, 0);
    }

    private static function assertNotNegative(string $name, int $value): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException("{$name} must be 0 or more, got {$value}");
        }
    }
}
