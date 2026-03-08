<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Freyr\MessageBroker\Command\SetupDeduplicationCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional test for SetupDeduplicationCommand --force against a real database (MySQL or PostgreSQL).
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

    #[Test]
    public function itCreatesTableWithCorrectSchemaInForceMode(): void
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

        // PostgreSQL maps BINARY(16) to bytea which does not preserve length metadata
        if (!self::$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->assertSame(16, $columns['message_id']->getLength());
        }
        $this->assertArrayHasKey('message_name', $columns);
        $this->assertArrayHasKey('processed_at', $columns);

        $indexes = $schemaManager->listTableIndexes(self::TABLE);
        $this->assertArrayHasKey('primary', $indexes);
        $this->assertArrayHasKey(sprintf('idx_%s_processed_at', self::TABLE), $indexes);
    }

    #[Test]
    public function itIsIdempotentInForceMode(): void
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

    #[Test]
    public function itShowsSqlWithoutExecutingInDryRun(): void
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
