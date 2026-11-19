<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeliveryRetention;
use PDOException;
use Symfony\Component\Uid\Uuid;

/**
 * Partitions of webhook_deliveries: daily partitions are created ahead
 * (CREATE + ATTACH). There is no DEFAULT partition.
 */
final class PostgreSqlDeliveryRetentionTest extends FunctionalTestCase
{
    private PostgreSqlDeliveryRetention $partitions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->dailyPartitionNames() as $name) {
            self::$pdo->exec("DROP TABLE \"{$name}\"");
        }
        // Tests that keep a detached daily table leave it behind.
        $leftovers = self::$pdo->query("SELECT relname FROM pg_class WHERE relkind = 'r' AND NOT relispartition AND relname ~ '^webhook_deliveries_p[0-9]{8}$'");
        self::assertNotFalse($leftovers);
        foreach ($leftovers->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            self::$pdo->exec(sprintf('DROP TABLE "%s"', (string) $name));
        }
        $this->partitions = new PostgreSqlDeliveryRetention(self::$pdo);
    }

    public function testEnsureCreatesOnePartitionPerDayAheadAndIsIdempotent(): void
    {
        $today = self::day('2026-03-10');

        $names = $this->partitions->ensureCapacity(2, $today);
        $this->partitions->ensureCapacity(2, $today);

        self::assertSame(
            ['webhook_deliveries_p20260310', 'webhook_deliveries_p20260311', 'webhook_deliveries_p20260312'],
            $names,
        );
        self::assertSame($names, $this->dailyPartitionNames());
    }

    public function testEnsureWithoutTodayStartsAtTheCurrentUtcDay(): void
    {
        $names = $this->partitions->ensureCapacity(0);

        self::assertSame(['webhook_deliveries_p'.gmdate('Ymd')], $names);
    }

    public function testPartitionDdlCreatesAndAttachesEachDayFromTodayThroughDaysAhead(): void
    {
        // A mid-day, non-UTC "today": the partitions still start at 00:00 UTC of that day.
        $today = new DateTimeImmutable('2026-03-10 13:45:30', new DateTimeZone('Europe/Warsaw'));

        $ddl = $this->partitions->partitionDdl(1, $today);

        self::assertSame([
            'CREATE TABLE IF NOT EXISTS "webhook_deliveries_p20260310" (LIKE webhook_deliveries INCLUDING DEFAULTS INCLUDING CONSTRAINTS)',
            "ALTER TABLE webhook_deliveries ATTACH PARTITION \"webhook_deliveries_p20260310\" FOR VALUES FROM ('2026-03-10 00:00:00') TO ('2026-03-11 00:00:00')",
            'CREATE TABLE IF NOT EXISTS "webhook_deliveries_p20260311" (LIKE webhook_deliveries INCLUDING DEFAULTS INCLUDING CONSTRAINTS)',
            "ALTER TABLE webhook_deliveries ATTACH PARTITION \"webhook_deliveries_p20260311\" FOR VALUES FROM ('2026-03-11 00:00:00') TO ('2026-03-12 00:00:00')",
        ], $ddl);
    }

    public function testPartitionDdlForZeroDaysAheadCoversOnlyToday(): void
    {
        self::assertCount(2, $this->partitions->partitionDdl(0, self::day('2026-03-10')));
    }

    public function testNegativeDaysAheadIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('daysAhead must be 0 or more, got -1');

        $this->partitions->partitionDdl(-1, self::day('2026-03-10'));
    }

    public function testEnsureRejectsNegativeDaysAheadAndCreatesNothing(): void
    {
        try {
            $this->partitions->ensureCapacity(-1, self::day('2026-03-10'));
            self::fail('a negative daysAhead must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('daysAhead must be 0 or more, got -1', $error->getMessage());
        }

        self::assertSame([], $this->dailyPartitionNames());
    }

    public function testEnsureAttachesAStandaloneDailyTableThatIsNotAttachedYet(): void
    {
        self::$pdo->exec('CREATE TABLE webhook_deliveries_p20260310 (LIKE webhook_deliveries INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');

        $names = $this->partitions->ensureCapacity(0, self::day('2026-03-10'));

        self::assertSame(['webhook_deliveries_p20260310'], $names);
        self::assertSame(['webhook_deliveries_p20260310'], $this->dailyPartitionNames(), 'the leftover table is now a partition');
        self::assertSame(0, $this->standaloneDailyTableCount());
    }

    public function testEnsureIgnoresATableOfAnotherNamePattern(): void
    {
        self::$pdo->exec('CREATE TABLE webhook_deliveries_p2026031 (LIKE webhook_deliveries)');
        try {
            $names = $this->partitions->ensureCapacity(0, self::day('2026-03-10'));

            self::assertSame(['webhook_deliveries_p20260310'], $names);
            self::assertSame(['webhook_deliveries_p20260310'], $this->dailyPartitionNames());
        } finally {
            self::$pdo->exec('DROP TABLE webhook_deliveries_p2026031');
        }
    }

    public function testRowsLandInTheirDailyPartition(): void
    {
        $this->partitions->ensureCapacity(0, self::day('2026-03-10'));

        $this->insertAt('2026-03-10 13:00:00');

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM webhook_deliveries_p20260310'));
    }

    public function testInsertForADayWithNoPartitionFails(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('no partition of relation');

        $this->insertAt('2026-03-10 13:00:00');
    }

    private function insertAt(string $createdAtUtc): void
    {
        $id = Uuid::v7()->toString();
        self::$pdo->exec(
            "INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at)
             VALUES ('{$id}', '{$id}', 'retention', 'order.placed', '\\x7b7d', '{}', '{$createdAtUtc}', '{$createdAtUtc}')",
        );
    }

    /** @return list<string> the daily partitions attached to webhook_deliveries */
    private function dailyPartitionNames(): array
    {
        $statement = self::$pdo->query(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'webhook_deliveries'::regclass AND c.relname LIKE 'webhook\\_deliveries\\_p%'
             ORDER BY c.relname",
        );
        self::assertNotFalse($statement);

        /** @var list<string> */
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function standaloneDailyTableCount(): int
    {
        return self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relkind = 'r' AND NOT relispartition AND relname ~ '^webhook_deliveries_p[0-9]{8}$'");
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
