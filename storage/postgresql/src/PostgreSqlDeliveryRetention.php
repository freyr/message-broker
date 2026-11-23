<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Outbox\RetentionReport;
use Freyr\MessageBroker\Webhook\DeliveryRetention;
use LogicException;
use PDO;
use RuntimeException;
use Symfony\Component\Clock\Clock;

/**
 * PostgreSQL implementation of DeliveryRetention: partitions and retention
 * of the webhook_deliveries table, with the rules of
 * PostgreSqlOutboxRetention. That class serves outbox_messages only (its
 * table, its "unpublished" condition and its options are fixed), so this
 * class repeats its code for the second table. Finished rows stay in the
 * table; they leave it here, one daily partition at a time.
 *
 *  - ensureCapacity() creates one range partition per UTC day
 *    (webhook_deliveries_pYYYYMMDD, [day, day + 1)) from today to today + N
 *    days. It creates a plain table and ATTACHes it, which takes only a SHARE
 *    UPDATE EXCLUSIVE lock on webhook_deliveries, so producers and delivery
 *    workers never wait. The name matches the pg_partman default for daily
 *    partitions, so pg_partman can create the partitions instead.
 *  - purge() drops each daily partition that lies wholly before the
 *    retention cutoff: DETACH PARTITION ... CONCURRENTLY (no ACCESS EXCLUSIVE
 *    lock on webhook_deliveries), then DROP TABLE on the detached table. An
 *    expired partition that holds an unfinished row (completed_at IS NULL) is
 *    kept and reported; there is no option to drop it. A delivery has no
 *    time limit, so the partition stays until each of its rows reaches a
 *    final state or an operator abandons it (DeliveryStore::abandon()).
 *
 * Safety. The check and the detach are not one atomic step, so purge() counts
 * the unfinished rows twice: before the detach, and again on the detached
 * table, right before DROP TABLE. A detached table that holds unfinished rows
 * is attached again at once, because the delivery loop claims rows through
 * webhook_deliveries; it is reported as kept. The same rule covers an expired
 * table that an earlier run detached but did not drop, and a partition with
 * a pending detach (it is finalized first). ATTACH takes only SHARE UPDATE
 * EXCLUSIVE on webhook_deliveries. If the attach fails, the exception
 * propagates: the table stays detached and nothing is dropped.
 *
 * There is no DEFAULT partition (DETACH ... CONCURRENTLY does not allow one):
 * an insert for a day with no partition fails. Create partitions ahead of
 * time.
 */
final readonly class PostgreSqlDeliveryRetention implements DeliveryRetention
{
    public const string TABLE = 'webhook_deliveries';
    private const string DAILY_PREFIX = 'webhook_deliveries_p';

    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * DDL that creates and attaches the daily partitions from $today to
     * $today + $daysAhead (inclusive). For a schema with no partition yet.
     * $today (default: the current UTC day) is a test parameter of this class.
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
     * $today (default: the current UTC day) is a test parameter of this class.
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
     * 00:00 UTC minus $retentionDays) and holds no unfinished row. A
     * partition with unfinished rows is kept and reported in
     * RetentionReport::$kept; there is no option to drop it. Also finishes a
     * detach that an earlier run did not complete, and drops an expired daily
     * table that an earlier run detached but did not drop (the same rule
     * applies to both).
     *
     * Must run outside a transaction: DETACH ... CONCURRENTLY cannot run in
     * a transaction block. $today (default: the current UTC day) is a test
     * parameter of this class, not part of the DeliveryRetention port.
     */
    public function purge(int $retentionDays, bool $dryRun = false, ?DateTimeImmutable $today = null): RetentionReport
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException("retentionDays must be 1 or more, got {$retentionDays}");
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Webhook delivery purge cannot run inside a transaction (DETACH PARTITION CONCURRENTLY).');
        }
        $cutoff = self::utcMidnight($today)->modify("-{$retentionDays} day");

        $dropped = [];
        $kept = [];
        foreach ($this->dailyTables() as $name => $table) {
            if ($table['day']->modify('+1 day') > $cutoff) {
                continue; // not wholly expired yet
            }

            $unfinished = $this->countUnfinished($name);
            if ($unfinished > 0) {
                if (!$dryRun && (!$table['attached'] || $table['detachPending'])) {
                    if ($table['detachPending']) {
                        $this->finalizeDetach($name);
                    }
                    $this->attach($name, $table['day']);
                }
                $kept[$name] = $unfinished;
                continue;
            }

            if (!$dryRun) {
                if ($table['detachPending']) {
                    $this->finalizeDetach($name);
                } elseif ($table['attached']) {
                    $this->pdo->exec(sprintf('ALTER TABLE %s DETACH PARTITION "%s" CONCURRENTLY', self::TABLE, $name));
                }
                // The table is detached now, so nothing can add a row. Count again:
                // a row may have arrived between the first count and the detach.
                $late = $this->countUnfinished($name);
                if ($late > 0) {
                    $this->attach($name, $table['day']);
                    $kept[$name] = $late;
                    continue;
                }
                $this->pdo->exec("DROP TABLE \"{$name}\"");
            }
            $dropped[] = $name;
        }

        return new RetentionReport($cutoff, $dropped, [], $kept);
    }

    private function finalizeDetach(string $name): void
    {
        $this->pdo->exec(sprintf('ALTER TABLE %s DETACH PARTITION "%s" FINALIZE', self::TABLE, $name));
    }

    private function attach(string $name, DateTimeImmutable $from): void
    {
        $this->pdo->exec(self::attachDdl($name, $from));
    }

    private function countUnfinished(string $table): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM \"{$table}\" WHERE completed_at IS NULL");
        $statement->execute();

        return (int) $statement->fetchColumn();
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
     * The daily tables in the schema of webhook_deliveries, oldest first: its
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
            throw new RuntimeException('Cannot list the webhook delivery partitions');
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
