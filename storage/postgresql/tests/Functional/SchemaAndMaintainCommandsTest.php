<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Console\OutboxMaintainCommand;
use Freyr\MessageBroker\Console\SetupSchemaCommand;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaAndMaintainCommandsTest extends FunctionalTestCase
{
    private PostgreSqlOutboxRetention $partitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partitions = new PostgreSqlOutboxRetention(self::$pdo);
    }

    public function testSetupSchemaDumpPrintsEveryStatementTerminatedWithASemicolon(): void
    {
        $tester = new CommandTester(new SetupSchemaCommand(self::schemaInstaller()));

        $status = $tester->execute([
            '--dump-sql' => true,
            '--premake-days' => '2',
        ]);

        $expected = '';
        foreach (self::schemaInstaller()->sql(2) as $statement) {
            $expected .= $statement.";\n";
        }
        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($expected, $tester->getDisplay());
        self::assertSame(3, preg_match_all('/CREATE TABLE IF NOT EXISTS "outbox_messages_p\d{8}"/', $tester->getDisplay()), 'today + 2 days ahead');
    }

    public function testSetupSchemaDumpDoesNotTouchTheDatabase(): void
    {
        self::$pdo->exec('DROP TABLE dead_letters');
        $tester = new CommandTester(new SetupSchemaCommand(self::schemaInstaller()));

        $tester->execute([
            '--dump-sql' => true,
        ]);

        self::assertStringNotContainsString('Schema is up to date', $tester->getDisplay());
        self::assertSame(0, self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relname = 'dead_letters'"));

        // Restore the table dropped above for the tests that follow.
        (new CommandTester(new SetupSchemaCommand(self::schemaInstaller())))->execute([]);
    }

    public function testSetupSchemaRecreatesMissingTablesAndPremakesPartitions(): void
    {
        self::$pdo->exec('DROP TABLE dead_letters');
        $tester = new CommandTester(new SetupSchemaCommand(self::schemaInstaller()));

        $status = $tester->execute([
            '--premake-days' => '5',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("Schema is up to date.\n", $tester->getDisplay());
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relname = 'dead_letters'"));
        $fiveDaysAhead = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 day')->format('Ymd');
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'outbox_messages'::regclass AND c.relname = 'outbox_messages_p{$fiveDaysAhead}'",
        ));
    }

    public function testANewInstallationHasTheAbandonedAtColumnAndKeepsThePendingIndexPredicate(): void
    {
        $column = self::$pdo->query(
            "SELECT data_type, datetime_precision, is_nullable, column_default FROM information_schema.columns
             WHERE table_name = 'outbox_messages' AND column_name = 'abandoned_at'",
        );
        self::assertNotFalse($column);
        self::assertSame([
            'data_type' => 'timestamp without time zone',
            'datetime_precision' => 3,
            'is_nullable' => 'YES',
            'column_default' => null,
        ], $column->fetch(\PDO::FETCH_ASSOC));

        $today = 'outbox_messages_p'.gmdate('Ymd');
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = '{$today}' AND column_name = 'abandoned_at'",
        ), 'a daily partition has the column too');

        $predicate = self::$pdo->query(
            "SELECT pg_get_expr(i.indpred, i.indrelid) FROM pg_index i
             WHERE i.indexrelid = 'idx_outbox_pending'::regclass",
        );
        self::assertNotFalse($predicate);
        self::assertSame('(published_at IS NULL)', $predicate->fetchColumn(), 'the index predicate does not change');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPremakeDays(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'text' => ['abc'];
        yield 'trailing text' => ['1x'];
        yield 'leading text' => ['x1'];
        yield 'decimal' => ['1.5'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidPremakeDays')]
    public function testSetupSchemaRejectsInvalidPremakeDays(string $value): void
    {
        $tester = new CommandTester(new SetupSchemaCommand(self::schemaInstaller()));

        $status = $tester->execute([
            '--premake-days' => $value,
            '--dump-sql' => true,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("--premake-days must be an integer of 0 or more.\n", $tester->getDisplay());
    }

    public function testSetupSchemaAcceptsZeroPremakeDays(): void
    {
        $tester = new CommandTester(new SetupSchemaCommand(self::schemaInstaller()));

        $status = $tester->execute([
            '--premake-days' => '0',
            '--dump-sql' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, preg_match_all('/CREATE TABLE IF NOT EXISTS "outbox_messages_p\d{8}"/', $tester->getDisplay()));
    }

    public function testMaintainReportsPartitionsAndCutoff(): void
    {
        $tester = new CommandTester(new OutboxMaintainCommand($this->partitions));

        $status = $tester->execute([
            '--retention-days' => '2',
            '--premake-days' => '1',
        ]);

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(
            sprintf(
                "Daily partitions present: outbox_messages_p%s, outbox_messages_p%s.\n",
                $today->format('Ymd'),
                $today->modify('+1 day')->format('Ymd'),
            ),
            $tester->getDisplay(),
        );
        self::assertStringContainsString(
            'Retention cutoff: '.$today->modify('-2 day')->format('Y-m-d H:i')." UTC.\n",
            $tester->getDisplay(),
        );
        self::assertStringNotContainsString('dry-run', $tester->getDisplay());
    }

    public function testMaintainAcceptsZeroPremakeDays(): void
    {
        $tester = new CommandTester(new OutboxMaintainCommand($this->partitions));

        $status = $tester->execute([
            '--premake-days' => '0',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Daily partitions present: outbox_messages_p'.gmdate('Ymd').".\n", $tester->getDisplay());
    }

    public function testMaintainRejectsInvalidOptionsWithAnExplanation(): void
    {
        $tester = new CommandTester(new OutboxMaintainCommand($this->partitions));

        $status = $tester->execute([
            '--retention-days' => '0',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame(
            "--retention-days must be an integer of 1 or more, --premake-days of 0 or more.\n",
            $tester->getDisplay(),
        );
    }

    public function testMaintainDryRunReportsWhatWouldBeKeptAndChangesNothing(): void
    {
        $old = $this->createExpiredPartitionWithUnpublishedRow();
        $name = 'outbox_messages_p'.$old->format('Ymd');
        $tester = new CommandTester(new OutboxMaintainCommand($this->partitions));

        $status = $tester->execute([
            '--retention-days' => '1',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status, 'a dry run always succeeds');
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::assertSame(
            sprintf(
                "[dry-run] would ensure daily partitions for today + 3 days.\n"
                ."[dry-run] Retention cutoff: %s UTC.\n"
                ."[dry-run] %s would be kept: it holds 1 unpublished row(s) older than the retention period. Publish them, or use --drop-unpublished to drop them.\n",
                $today->modify('-1 day')->format('Y-m-d H:i'),
                $name,
            ),
            $tester->getDisplay(),
        );
        self::assertContains($name, $this->attachedDailyPartitions(), 'dry-run keeps the partition');

        $real = new CommandTester(new OutboxMaintainCommand($this->partitions));
        $status = $real->execute([
            '--retention-days' => '1',
        ]);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString("\n{$name} is kept: it holds 1 unpublished row(s)", $real->getDisplay());
        self::assertStringNotContainsString('dry-run', $real->getDisplay());
        self::assertContains($name, $this->attachedDailyPartitions());
    }

    public function testMaintainDryRunWithDropUnpublishedReportsWhatWouldBeDropped(): void
    {
        $old = $this->createExpiredPartitionWithUnpublishedRow();
        $name = 'outbox_messages_p'.$old->format('Ymd');
        $tester = new CommandTester(new OutboxMaintainCommand($this->partitions));

        $status = $tester->execute([
            '--retention-days' => '1',
            '--dry-run' => true,
            '--drop-unpublished' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("[dry-run] Dropped {$name}.\n", $tester->getDisplay());
        self::assertStringContainsString("[dry-run] {$name} held 1 unpublished row(s) older than the retention period; they are dropped.\n", $tester->getDisplay());
        self::assertContains($name, $this->attachedDailyPartitions(), 'dry-run keeps the partition');

        $real = new CommandTester(new OutboxMaintainCommand($this->partitions));
        $status = $real->execute([
            '--retention-days' => '1',
            '--drop-unpublished' => true,
        ]);
        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("\nDropped {$name}.\n", $real->getDisplay());
        self::assertNotContains($name, $this->attachedDailyPartitions());
    }

    private function createExpiredPartitionWithUnpublishedRow(): DateTimeImmutable
    {
        $old = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 day')->setTime(0, 0);
        $this->partitions->ensureCapacity(0, $old);
        (new PostgreSqlOutboxStore(self::$pdo))->insert(new OutboxRecord(
            id: '01900000-0000-7000-8000-0000000000f1',
            lane: 'retention',
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: $old->modify('+10 hour')->getTimestamp() * 1000,
        ));

        return $old;
    }

    /** @return list<string> */
    private function attachedDailyPartitions(): array
    {
        $statement = self::$pdo->query(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'outbox_messages'::regclass AND c.relname LIKE 'outbox\\_messages\\_p%'
             ORDER BY c.relname",
        );
        self::assertNotFalse($statement);

        /** @var list<string> $names */
        $names = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return $names;
    }
}
