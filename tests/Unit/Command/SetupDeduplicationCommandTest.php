<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\StringType;
use Freyr\MessageBroker\Command\SetupDeduplicationCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for SetupDeduplicationCommand.
 *
 * Tests schema definition indirectly via dry-run mode with a mocked connection.
 */
final class SetupDeduplicationCommandTest extends TestCase
{
    public function testDryRunShowsSqlWhenTableDoesNotExist(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')
            ->with(['message_broker_deduplication'])
            ->willReturn(false);

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getCreateTableSQL')
            ->willReturnCallback(function (Table $table): array {
                // Verify schema definition
                $this->assertSame('message_broker_deduplication', $table->getName());
                $this->assertCount(3, $table->getColumns());

                $messageId = $table->getColumn('message_id');
                $this->assertInstanceOf(BinaryType::class, $messageId->getType());
                $this->assertSame(16, $messageId->getLength());
                $this->assertTrue($messageId->getFixed());
                $this->assertTrue($messageId->getNotnull());
                $this->assertSame('(DC2Type:id_binary)', $messageId->getComment());

                $messageName = $table->getColumn('message_name');
                $this->assertInstanceOf(StringType::class, $messageName->getType());
                $this->assertSame(255, $messageName->getLength());

                $processedAt = $table->getColumn('processed_at');
                $this->assertInstanceOf(DateTimeType::class, $processedAt->getType());

                $primaryKey = $table->getPrimaryKey();
                $this->assertNotNull($primaryKey);
                $this->assertSame(['message_id'], $primaryKey->getColumns());
                $this->assertTrue($table->hasIndex('idx_dedup_message_name'));
                $this->assertTrue($table->hasIndex('idx_dedup_processed_at'));

                return ['CREATE TABLE message_broker_deduplication (...)'];
            });

        $connection = $this->createMock(Connection::class);
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
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')
            ->with(['message_broker_deduplication'])
            ->willReturn(true);

        $connection = $this->createMock(Connection::class);
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
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')
            ->with(['custom_dedup'])
            ->willReturn(false);

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getCreateTableSQL')
            ->willReturnCallback(function (Table $table): array {
                $this->assertSame('custom_dedup', $table->getName());

                return ['CREATE TABLE custom_dedup (...)'];
            });

        $connection = $this->createMock(Connection::class);
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

    public function testConflictingFlagsProduceError(): void
    {
        $connection = $this->createMock(Connection::class);

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
        $connection = $this->createMock(Connection::class);

        // No migrations configuration injected (null)
        $command = new SetupDeduplicationCommand($connection, 'message_broker_deduplication');
        $tester = new CommandTester($command);
        $tester->execute([
            '--migration' => true,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Could not determine migrations configuration', $tester->getDisplay());
    }
}
