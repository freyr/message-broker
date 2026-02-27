<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Command\SetupDeduplicationCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional test for SetupDeduplicationCommand --force against real MySQL.
 *
 * Uses a unique table name to avoid colliding with the shared deduplication schema.
 * Verifies that the generated DDL creates a valid table with the correct structure.
 */
#[CoversClass(SetupDeduplicationCommand::class)]
final class SetupDeduplicationCommandTest extends FunctionalDatabaseTestCase
{
    private const TABLE = 'test_setup_cmd_deduplication';

    protected function setUp(): void
    {
        self::$connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
        parent::tearDownAfterClass();
    }

    public function testForceCreatesTableWithCorrectSchema(): void
    {
        $command = new SetupDeduplicationCommand(self::$connection, self::TABLE);
        $tester = new CommandTester($command);
        $tester->execute([
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $schemaManager = self::$connection->createSchemaManager();
        $this->assertTrue($schemaManager->tablesExist([self::TABLE]));

        $columns = $schemaManager->listTableColumns(self::TABLE);
        $this->assertArrayHasKey('message_id', $columns);
        $this->assertSame(16, $columns['message_id']->getLength());
        $this->assertArrayHasKey('message_name', $columns);
        $this->assertArrayHasKey('processed_at', $columns);

        $indexes = $schemaManager->listTableIndexes(self::TABLE);
        $this->assertArrayHasKey('primary', $indexes);
        $this->assertArrayHasKey('idx_dedup_processed_at', $indexes);
    }

    public function testForceIsIdempotent(): void
    {
        $command = new SetupDeduplicationCommand(self::$connection, self::TABLE);
        $tester = new CommandTester($command);

        $tester->execute([
            '--force' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('created successfully', $tester->getDisplay());

        $tester->execute([
            '--force' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testDryRunShowsSqlWithoutExecuting(): void
    {
        $command = new SetupDeduplicationCommand(self::$connection, self::TABLE);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('CREATE TABLE', $tester->getDisplay());

        $schemaManager = self::$connection->createSchemaManager();
        $this->assertFalse($schemaManager->tablesExist([self::TABLE]), 'Dry-run should not create the table');
    }
}
