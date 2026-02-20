<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\Configuration\Configuration as MigrationsConfiguration;
use Freyr\MessageBroker\Command\SetupDeduplicationCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit test for SetupDeduplicationCommand.
 *
 * Tests that the command:
 * - Shows SQL in dry-run mode (default)
 * - Reports when table already exists
 * - Uses custom table name
 * - Creates table in force mode
 * - Skips in force mode when table exists
 * - Generates migration file
 * - Rejects conflicting --force and --migration flags
 * - Reports missing migrations configuration
 */
#[CoversClass(SetupDeduplicationCommand::class)]
final class SetupDeduplicationCommandTest extends TestCase
{
    public function testDryRunShowsSqlWhenTableDoesNotExist(): void
    {
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')
            ->willReturn(false);

        $platform = $this->createStub(AbstractPlatform::class);
        $platform->method('getCreateTableSQL')
            ->willReturnCallback(function (Table $table): array {
                $this->assertSame('message_broker_deduplication', $table->getName());
                $this->assertCount(3, $table->getColumns());
                $this->assertTrue($table->hasColumn('message_id'));
                $this->assertTrue($table->hasColumn('message_name'));
                $this->assertTrue($table->hasColumn('processed_at'));
                $this->assertSame(['message_id'], $table->getPrimaryKey()?->getColumns());
                $this->assertTrue($table->hasIndex('idx_dedup_processed_at'));

                return ['CREATE TABLE message_broker_deduplication (...)'];
            });

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')
            ->willReturn($schemaManager);
        $connection->method('getDatabasePlatform')
            ->willReturn($platform);

        $command = new SetupDeduplicationCommand($connection, 'message_broker_deduplication');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('CREATE TABLE', $tester->getDisplay());
    }

    public function testDryRunReportsTableExists(): void
    {
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')
            ->willReturn(true);

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $command = new SetupDeduplicationCommand($connection, 'message_broker_deduplication');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testCustomTableNameIsUsed(): void
    {
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')
            ->willReturn(false);

        $platform = $this->createStub(AbstractPlatform::class);
        $platform->method('getCreateTableSQL')
            ->willReturnCallback(function (Table $table): array {
                $this->assertSame('custom_dedup', $table->getName());

                return ['CREATE TABLE custom_dedup (...)'];
            });

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')
            ->willReturn($schemaManager);
        $connection->method('getDatabasePlatform')
            ->willReturn($platform);

        $command = new SetupDeduplicationCommand($connection, 'custom_dedup');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('CREATE TABLE custom_dedup', $tester->getDisplay());
    }

    public function testForceModeCreatesTable(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')
            ->willReturn(false);
        $schemaManager->expects($this->once())
            ->method('createTable');

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $command = new SetupDeduplicationCommand($connection, 'message_broker_deduplication');
        $tester = new CommandTester($command);
        $tester->execute([
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('created successfully', $tester->getDisplay());
    }

    public function testForceModeSkipsWhenTableExists(): void
    {
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')
            ->willReturn(true);

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $command = new SetupDeduplicationCommand($connection, 'message_broker_deduplication');
        $tester = new CommandTester($command);
        $tester->execute([
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testMigrationModeGeneratesFile(): void
    {
        $tempDir = sys_get_temp_dir().'/test_migrations_'.uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            $migrationsConfig = new MigrationsConfiguration();
            $migrationsConfig->addMigrationsDirectory('App\\Migrations', $tempDir);

            $connection = $this->createStub(Connection::class);

            $command = new SetupDeduplicationCommand($connection, 'message_broker_deduplication', $migrationsConfig);
            $tester = new CommandTester($command);
            $tester->execute([
                '--migration' => true,
            ]);

            $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
            $this->assertStringContainsString('Migration file generated', $tester->getDisplay());

            $files = glob($tempDir.'/Version*.php');
            $this->assertNotEmpty($files, 'Migration file should have been created');
        } finally {
            $files = glob($tempDir.'/*');
            if (is_array($files)) {
                array_map('unlink', $files);
            }
            rmdir($tempDir);
        }
    }

    public function testConflictingFlagsProduceError(): void
    {
        $connection = $this->createStub(Connection::class);

        $command = new SetupDeduplicationCommand($connection, 'message_broker_deduplication');
        $tester = new CommandTester($command);
        $tester->execute([
            '--force' => true,
            '--migration' => true,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('mutually exclusive', $tester->getDisplay());
    }

    public function testMigrationModeWithoutConfigurationErrors(): void
    {
        $connection = $this->createStub(Connection::class);

        $command = new SetupDeduplicationCommand($connection, 'message_broker_deduplication');
        $tester = new CommandTester($command);
        $tester->execute([
            '--migration' => true,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Could not determine migrations configuration', $tester->getDisplay());
    }
}
