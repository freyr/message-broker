<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Console\OutboxMaintainCommand;
use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use PDO;
use PDOException;
use PDOStatement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The migration note of UPGRADE.md (0.9.0) on a table of 0.8.x with no
 * abandoned_at column: attached daily partitions, an expired detached table, a
 * partition with a pending detach, and a daily table that stays outside the
 * parent. The steps run in the order of the note. ALTER TABLE on the parent
 * changes only the attached partitions, so a table outside the parent needs
 * its own ALTER TABLE, or a later ATTACH fails.
 */
final class AbandonedAtMigrationTest extends FunctionalTestCase
{
    private const string OLD_TABLE = <<<'SQL'
        CREATE TABLE outbox_messages (
            id UUID NOT NULL,
            lane VARCHAR(64) NOT NULL,
            message_name VARCHAR(255) NOT NULL,
            body BYTEA NOT NULL,
            headers JSONB NOT NULL,
            created_at TIMESTAMP(3) NOT NULL,
            available_at TIMESTAMP(3) NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            published_at TIMESTAMP(3) NULL,
            PRIMARY KEY (id, created_at)
        ) PARTITION BY RANGE (created_at)
        SQL;

    public static function tearDownAfterClass(): void
    {
        // The next test class installs the current schema again (setUpBeforeClass).
        self::$pdo->exec('DROP TABLE IF EXISTS outbox_messages');
    }

    public function testTheStepsOfTheNoteGiveEveryDailyTableTheColumnAndALaterAttachWorks(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $this->installTheOldSchema($today);
        $expiredAttached = self::dailyName($today->modify('-10 day'));
        $expiredDetached = self::dailyName($today->modify('-9 day'));
        $detachPending = self::dailyName($today->modify('-8 day'));
        $outside = self::dailyName($today->modify('+10 day'));
        $skipped = self::dailyName($today->modify('+11 day'));
        self::assertSame([$expiredDetached, $outside, $skipped], $this->tablesOutsideTheParent());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM pg_inherits WHERE inhdetachpending'));

        // Step 1: outbox:maintain once by hand, with the code of 0.8.x.
        $maintain = new CommandTester(new OutboxMaintainCommand(new PostgreSqlOutboxRetention(self::oldCodeConnection())));
        self::assertSame(Command::FAILURE, $maintain->execute([
            '--retention-days' => '7',
        ]), 'the expired partitions hold unpublished rows: they are kept');
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM pg_inherits WHERE inhdetachpending'), 'the pending detach is finished');
        self::assertSame([$outside, $skipped], $this->tablesOutsideTheParent(), 'the expired detached table is attached again');
        // ... then the check, and an ALTER TABLE for each table outside the parent.
        // $skipped stands for a table that an operator missed.
        self::$pdo->exec("ALTER TABLE \"{$outside}\" ADD COLUMN abandoned_at TIMESTAMP(3) NULL");

        // Step 2: the column on the parent and each attached partition, then the check again.
        self::$pdo->exec("SET lock_timeout = '5s'");
        self::$pdo->exec('ALTER TABLE outbox_messages ADD COLUMN abandoned_at TIMESTAMP(3) NULL');
        self::$pdo->exec('RESET lock_timeout');
        self::assertSame([$outside, $skipped], $this->tablesOutsideTheParent());

        // Step 3: the new code.
        foreach ($this->dailyTables() as $name) {
            self::assertSame($name !== $skipped ? 1 : 0, $this->hasColumn($name), "{$name}: abandoned_at");
        }
        self::assertContains($expiredAttached, $this->attachedTables());
        self::assertContains($detachPending, $this->attachedTables());

        $retention = new PostgreSqlOutboxRetention(self::$pdo);
        $retention->ensureCapacity(10, $today); // attaches the table outside the parent
        self::assertSame([$skipped], $this->tablesOutsideTheParent(), 'a later attach of the altered table works');
        try {
            $retention->ensureCapacity(11, $today);
            self::fail('a table with no abandoned_at cannot be attached');
        } catch (PDOException $error) {
            self::assertStringContainsString('abandoned_at', $error->getMessage());
        }
        self::$pdo->exec("ALTER TABLE \"{$skipped}\" ADD COLUMN abandoned_at TIMESTAMP(3) NULL");
        $retention->ensureCapacity(11, $today);
        self::assertSame([], $this->tablesOutsideTheParent());

        // The new code works on the migrated table: abandon, claim, retention.
        $store = new PostgreSqlOutboxStore(self::$pdo);
        self::assertSame(1, $store->abandon('01900000-0000-7000-8000-0000000000a1'), 'a row of the expired partition');
        $store->drainClaimed('migration', 10, static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(
            array_map(static fn (OutboxRecord $record): string => $record->id, $claimed),
        ));
        $report = $retention->purge(7, today: $today);
        self::assertSame([$expiredAttached, $expiredDetached, $detachPending], $report->dropped);
        self::assertSame([
            $expiredAttached => 1,
        ], $report->abandoned);
        self::assertSame([], $report->kept);
    }

    private function installTheOldSchema(DateTimeImmutable $today): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS outbox_messages');
        $leftovers = self::$pdo->query("SELECT relname FROM pg_class WHERE relkind = 'r' AND relname ~ '^outbox_messages_p[0-9]{8}$'");
        self::assertNotFalse($leftovers);
        foreach ($leftovers->fetchAll(PDO::FETCH_COLUMN) as $name) {
            self::$pdo->exec(sprintf('DROP TABLE "%s"', (string) $name));
        }
        self::$pdo->exec(self::OLD_TABLE);
        self::$pdo->exec('CREATE INDEX idx_outbox_pending ON outbox_messages (lane, available_at) WHERE published_at IS NULL');

        // The partition DDL takes the columns of the parent: no abandoned_at.
        $retention = new PostgreSqlOutboxRetention(self::$pdo);
        $retention->ensureCapacity(3, $today);
        foreach ([-10, -9, -8] as $offset) {
            $day = $today->modify("{$offset} day");
            $retention->ensureCapacity(0, $day);
            self::$pdo->exec(sprintf(
                "INSERT INTO outbox_messages (id, lane, message_name, body, headers, created_at, available_at, attempts)
                 VALUES ('01900000-0000-7000-8000-0000000000%s', 'migration', 'm', '\\x7b7d', '{}', '%s', '%s', 0)",
                [
                    '-10' => 'a1',
                    '-9' => 'a2',
                    '-8' => 'a3',
                ][(string) $offset],
                $day->format('Y-m-d 10:00:00'),
                $day->format('Y-m-d 10:00:00'),
            ));
        }
        // An earlier run detached this expired partition and stopped before the drop.
        self::$pdo->exec(sprintf('ALTER TABLE outbox_messages DETACH PARTITION "%s"', self::dailyName($today->modify('-9 day'))));
        $this->leaveDetachPending(self::dailyName($today->modify('-8 day')));
        // Daily tables outside the parent that outbox:maintain does not touch (not expired, beyond the premake days).
        foreach ([10, 11] as $offset) {
            self::$pdo->exec(sprintf('CREATE TABLE "%s" (LIKE outbox_messages INCLUDING DEFAULTS INCLUDING CONSTRAINTS)', self::dailyName($today->modify("+{$offset} day"))));
        }
        self::assertSame(0, self::fetchInt("SELECT COUNT(*) FROM information_schema.columns WHERE column_name = 'abandoned_at'"));
    }

    /**
     * A connection that runs the retention SQL of 0.8.x: the 0.9.0 class with
     * the abandoned_at conditions removed (0.8.x counted published_at IS NULL
     * and had no count of abandoned rows). The rest of the retention class is
     * the same in both versions.
     */
    private static function oldCodeConnection(): PDO
    {
        [$dsn, $user, $password] = self::credentials();

        return new class($dsn, $user, $password) extends PDO {
            public function __construct(string $dsn, string $user, string $password)
            {
                parent::__construct($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                $old = str_replace(
                    [' AND abandoned_at IS NULL', 'published_at IS NULL AND abandoned_at IS NOT NULL'],
                    ['', 'false'],
                    $query,
                );

                return parent::query($old);
            }
        };
    }

    /** The second phase of DETACH ... CONCURRENTLY waits for an open transaction; a timeout leaves it pending. */
    private function leaveDetachPending(string $partition): void
    {
        $rival = self::newConnection();
        $rival->beginTransaction();
        $rival->query('SELECT COUNT(*) FROM outbox_messages');
        self::$pdo->exec("SET statement_timeout = '1s'");
        try {
            self::$pdo->exec("ALTER TABLE outbox_messages DETACH PARTITION \"{$partition}\" CONCURRENTLY");
            self::fail('the detach must wait for the open transaction');
        } catch (PDOException) {
        } finally {
            self::$pdo->exec('RESET statement_timeout');
        }
        $rival->rollBack();
    }

    private function hasColumn(string $table): int
    {
        return self::fetchInt("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = '{$table}' AND column_name = 'abandoned_at'");
    }

    /** @return list<string> */
    private function dailyTables(): array
    {
        return $this->names("SELECT relname FROM pg_class WHERE relkind = 'r' AND relname ~ '^outbox_messages_p[0-9]{8}$' ORDER BY relname");
    }

    /** @return list<string> */
    private function attachedTables(): array
    {
        return $this->names("SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE i.inhparent = 'outbox_messages'::regclass ORDER BY c.relname");
    }

    /** @return list<string> the result of the check query of the note (UPGRADE.md), in name order */
    private function tablesOutsideTheParent(): array
    {
        return $this->names(<<<'SQL'
            SELECT c.relname FROM pg_class c
            WHERE c.relkind = 'r' AND c.relname ~ '^outbox_messages_p[0-9]{8}$'
              AND NOT EXISTS (SELECT 1 FROM pg_inherits i
                              WHERE i.inhrelid = c.oid AND i.inhparent = 'outbox_messages'::regclass)
            ORDER BY c.relname
            SQL);
    }

    /** @return list<string> */
    private function names(string $sql): array
    {
        $statement = self::$pdo->query($sql);
        self::assertNotFalse($statement);

        /** @var list<string> $names */
        $names = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }

    private static function dailyName(DateTimeImmutable $day): string
    {
        return 'outbox_messages_p'.$day->format('Ymd');
    }
}
