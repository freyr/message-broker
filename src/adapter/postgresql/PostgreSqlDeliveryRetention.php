<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Symfony\Component\Clock\Clock;

/**
 * Partitions of the webhook_deliveries table, with the rules of
 * PostgreSqlOutboxRetention (that class serves outbox_messages only, so this
 * class repeats its partition code for the second table).
 *
 *  - ensureCapacity() creates one range partition per UTC day
 *    (webhook_deliveries_pYYYYMMDD, [day, day + 1)) from today to today + N
 *    days. It creates a plain table and ATTACHes it, which takes only a SHARE
 *    UPDATE EXCLUSIVE lock on webhook_deliveries, so producers and delivery
 *    workers never wait. The name matches the pg_partman default for daily
 *    partitions, so pg_partman can create the partitions instead.
 *
 * There is no DEFAULT partition: an insert for a day with no partition fails.
 * Create partitions ahead of time.
 */
final readonly class PostgreSqlDeliveryRetention
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
