<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Command;

use Doctrine\DBAL\Connection;
use Freyr\MessageBroker\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional tests for the deduplication setup command.
 *
 * Verifies all three modes (dry-run, force, migration) against a real MySQL database.
 */
final class SetupDeduplicationCommandTest extends FunctionalTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('message-broker:setup-deduplication');
        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        // Ensure the deduplication table exists for subsequent tests
        // (some tests drop it to verify creation behaviour)
        $this->ensureDeduplicationTableExists();
        parent::tearDown();
    }

    public function testDryRunShowsSqlWhenTableDoesNotExist(): void
    {
        $this->dropDeduplicationTable();

        $exitCode = $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('CREATE TABLE', $this->commandTester->getDisplay());
    }

    public function testDryRunReportsTableExists(): void
    {
        // Table exists from schema.sql setup
        $exitCode = $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('already exists', $this->commandTester->getDisplay());
    }

    public function testForceModeCreatesTable(): void
    {
        $this->dropDeduplicationTable();

        $exitCode = $this->commandTester->execute([
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('created successfully', $this->commandTester->getDisplay());

        // Verify table was actually created
        $schemaManager = $this->getConnection()
            ->createSchemaManager();
        $this->assertTrue($schemaManager->tablesExist(['message_broker_deduplication']));
    }

    public function testForceModeIsIdempotent(): void
    {
        // Table already exists from schema.sql setup
        $exitCode = $this->commandTester->execute([
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('already exists', $this->commandTester->getDisplay());
    }

    public function testMigrationModeGeneratesFile(): void
    {
        $exitCode = $this->commandTester->execute([
            '--migration' => true,
        ]);

        $display = $this->commandTester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, 'Command failed with output: '.$display);
        $this->assertStringContainsString('Migration file generated', $display);

        // Extract file path from output (SymfonyStyle wraps with whitespace/borders)
        preg_match('/Migration file generated:\s*(.+\.php)/', $display, $matches);
        $this->assertNotEmpty($matches[1] ?? '', 'Could not extract file path from: '.$display);
        $filePath = trim($matches[1]);

        // Verify file exists and contains correct content
        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);
        $this->assertIsString($content);
        $this->assertStringContainsString('namespace DoctrineMigrations', $content);
        $this->assertStringContainsString('Types::BINARY', $content);
        $this->assertStringContainsString('Types::STRING', $content);
        $this->assertStringContainsString('Types::DATETIME_MUTABLE', $content);
        $this->assertStringContainsString("'message_broker_deduplication'", $content);
        $this->assertStringNotContainsString('idx_dedup_message_name', $content);
        $this->assertStringContainsString('idx_dedup_processed_at', $content);
        $this->assertStringContainsString('(DC2Type:id_binary)', $content);
        $this->assertStringContainsString('dropTable', $content);

        // Clean up generated file
        unlink($filePath);
    }

    public function testConflictingFlagsProduceError(): void
    {
        $exitCode = $this->commandTester->execute([
            '--force' => true,
            '--migration' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('mutually exclusive', $this->commandTester->getDisplay());
    }

    public function testForceModeThenDryRunConfirmsTable(): void
    {
        $this->dropDeduplicationTable();

        // Create table
        $this->commandTester->execute([
            '--force' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

        // Dry-run should report it exists
        $this->commandTester->execute([]);
        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('already exists', $this->commandTester->getDisplay());
    }

    private function dropDeduplicationTable(): void
    {
        $schemaManager = $this->getConnection()
            ->createSchemaManager();
        if ($schemaManager->tablesExist(['message_broker_deduplication'])) {
            $schemaManager->dropTable('message_broker_deduplication');
        }
    }

    private function ensureDeduplicationTableExists(): void
    {
        $connection = $this->getConnection();
        $schemaManager = $connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['message_broker_deduplication'])) {
            $connection->executeStatement("
                CREATE TABLE message_broker_deduplication (
                    message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
                    message_name VARCHAR(255) NOT NULL,
                    processed_at DATETIME NOT NULL,
                    INDEX idx_dedup_processed_at (processed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    private function getConnection(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
