<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Clock\Clock;

/**
 * Retention for the partitioned outbox_messages table. Published rows are
 * never deleted by the relay; they leave the table here:
 *
 *  - ensurePartitions() creates one range partition per UTC day
 *    (outbox_messages_pYYYYMMDD, [day, day + 1)) from today to today + N days.
 *  - purge() drops each daily partition that lies wholly before the retention
 *    cutoff AND holds no unpublished row. A partition with unpublished rows is
 *    kept and reported — those rows still wait for the relay, and dropping them
 *    would lose messages. In the DEFAULT partition (rows that arrived while no
 *    daily partition existed) it deletes published rows older than the cutoff.
 *
 * Run it on a schedule (e.g. daily cron): message-broker:outbox:maintain.
 * Daily partitions must exist BEFORE rows of that day arrive; a row that lands
 * in DEFAULT blocks the later creation of its day's partition.
 */
final readonly class OutboxPartitionManager
{
    public const string TABLE = 'outbox_messages';
    public const string DEFAULT_PARTITION = 'outbox_messages_default';
    private const string DAILY_PREFIX = 'outbox_messages_p';

    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * DDL for the daily partitions from $today to $today + $daysAhead (inclusive).
     *
     * @return list<string>
     */
    public function partitionDdl(int $daysAhead, ?DateTimeImmutable $today = null): array
    {
        self::assertPositive('daysAhead', $daysAhead, allowZero: true);
        $day = self::utcMidnight($today);

        $ddl = [];
        for ($offset = 0; $offset <= $daysAhead; ++$offset) {
            $from = $day->modify("+{$offset} day");
            $to = $from->modify('+1 day');
            $ddl[] = sprintf(
                "CREATE TABLE IF NOT EXISTS \"%s\" PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                self::partitionName($from),
                self::TABLE,
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            );
        }

        return $ddl;
    }

    /**
     * Create the missing daily partitions from today to today + $daysAhead.
     *
     * @return list<string> the daily partition names that now exist for that range
     */
    public function ensurePartitions(int $daysAhead = 3, ?DateTimeImmutable $today = null): array
    {
        $names = [];
        $day = self::utcMidnight($today);
        foreach ($this->partitionDdl($daysAhead, $day) as $offset => $ddl) {
            $name = self::partitionName($day->modify("+{$offset} day"));
            try {
                $this->pdo->exec($ddl);
            } catch (PDOException $error) {
                throw new RuntimeException(
                    "Cannot create outbox partition {$name}: ".$error->getMessage()
                    .' — if rows of that day already sit in '.self::DEFAULT_PARTITION
                    .', move them out of it first; run maintenance with enough --premake-days to avoid this.',
                    previous: $error,
                );
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Drop expired daily partitions that hold only published rows, and delete
     * expired published rows from the DEFAULT partition.
     */
    public function purge(int $retentionDays, bool $dryRun = false, ?DateTimeImmutable $today = null): PartitionPurgeReport
    {
        self::assertPositive('retentionDays', $retentionDays);
        $cutoff = self::utcMidnight($today)->modify("-{$retentionDays} day");

        $dropped = [];
        $kept = [];
        foreach ($this->dailyPartitions() as $name => $day) {
            if ($day->modify('+1 day') > $cutoff) {
                continue; // not wholly expired yet
            }

            $pending = $this->count("SELECT COUNT(*) FROM \"{$name}\" WHERE published_at IS NULL");
            if ($pending > 0) {
                $kept[$name] = $pending;
                continue;
            }

            if (!$dryRun) {
                $this->pdo->exec("DROP TABLE \"{$name}\"");
            }
            $dropped[] = $name;
        }

        $expiredInDefault = 'FROM '.self::DEFAULT_PARTITION.' WHERE published_at IS NOT NULL AND created_at < :cutoff';
        $cutoffParam = [
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
        ];
        if ($dryRun) {
            $defaultDeleted = $this->count("SELECT COUNT(*) {$expiredInDefault}", $cutoffParam);
            $defaultRemaining = $this->count('SELECT COUNT(*) FROM '.self::DEFAULT_PARTITION) - $defaultDeleted;
        } else {
            $statement = $this->pdo->prepare("DELETE {$expiredInDefault}");
            $statement->execute($cutoffParam);
            $defaultDeleted = $statement->rowCount();
            $defaultRemaining = $this->count('SELECT COUNT(*) FROM '.self::DEFAULT_PARTITION);
        }

        return new PartitionPurgeReport($cutoff, $dropped, $kept, $defaultDeleted, $defaultRemaining);
    }

    /**
     * The daily partitions attached to outbox_messages, oldest first. Names
     * come from the catalog and must match the daily naming pattern, so they
     * are safe to interpolate into DDL.
     *
     * @return array<string, DateTimeImmutable> partition name => its UTC day
     */
    private function dailyPartitions(): array
    {
        $statement = $this->pdo->query(
            'SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid'
            ." WHERE i.inhparent = '".self::TABLE."'::regclass ORDER BY c.relname",
        );
        if ($statement === false) {
            throw new RuntimeException('Cannot list the outbox partitions');
        }

        $partitions = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (!is_string($name) || preg_match('/^'.self::DAILY_PREFIX.'(\d{8})$/', $name, $match) !== 1) {
                continue; // DEFAULT and any foreign partition are not daily partitions
            }
            $day = DateTimeImmutable::createFromFormat('!Ymd', $match[1], new DateTimeZone('UTC'));
            if ($day !== false) {
                $partitions[$name] = $day;
            }
        }

        return $partitions;
    }

    /** @param array<string, string> $params */
    private function count(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

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

    private static function assertPositive(string $name, int $value, bool $allowZero = false): void
    {
        if ($value < 0 || (!$allowZero && $value === 0)) {
            throw new \InvalidArgumentException("{$name} must be ".($allowZero ? '0 or more' : '1 or more').", got {$value}");
        }
    }
}
