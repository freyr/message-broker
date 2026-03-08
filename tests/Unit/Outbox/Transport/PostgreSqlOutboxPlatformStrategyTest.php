<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\StringType;
use Freyr\MessageBroker\Outbox\Transport\OutboxPlatformStrategy;
use Freyr\MessageBroker\Outbox\Transport\PostgreSqlOutboxPlatformStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PostgreSqlOutboxPlatformStrategy::class)]
final class PostgreSqlOutboxPlatformStrategyTest extends TestCase
{
    private PostgreSqlOutboxPlatformStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new PostgreSqlOutboxPlatformStrategy();
    }

    #[Test]
    public function itImplementsOutboxPlatformStrategy(): void
    {
        $this->assertInstanceOf(OutboxPlatformStrategy::class, $this->strategy);
    }

    #[Test]
    public function itBuildsInsertReturningQuery(): void
    {
        $connection = $this->createMock(Connection::class);

        $values = [
            'body' => '{"payload":"test"}',
            'headers' => '{"type":"test.event"}',
            'queue_name' => 'outbox',
        ];
        $types = [
            'body' => 'text',
        ];

        $result = $this->createStub(Result::class);
        $result->method('fetchOne')
            ->willReturn(99);

        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('INSERT INTO messenger_outbox', $sql);
                    $this->assertStringContainsString('RETURNING id', $sql);
                    $this->assertStringContainsString('body', $sql);
                    $this->assertStringContainsString('headers', $sql);
                    $this->assertStringContainsString('queue_name', $sql);

                    return true;
                }),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($result);

        $connection->expects($this->never())
            ->method('insert');
        $connection->expects($this->never())
            ->method('lastInsertId');

        $id = $this->strategy->insertAndReturnId($connection, 'messenger_outbox', $values, $types);

        $this->assertSame('99', $id);
    }

    #[Test]
    public function itReturnsXid8HeadOfLineFilter(): void
    {
        $filter = $this->strategy->buildHeadOfLineFilter();

        $this->assertStringContainsString('transaction_id', $filter);
        $this->assertStringContainsString('pg_snapshot_xmin', $filter);
        $this->assertStringContainsString('pg_current_snapshot', $filter);
        $this->assertStringStartsWith(' AND ', $filter);
    }

    #[Test]
    public function itAddsTransactionIdColumnWhenMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $platform = $this->createStub(PostgreSQLPlatform::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($platform);

        /** @var AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform>&\PHPUnit\Framework\MockObject\MockObject $schemaManager */
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableColumns')
            ->with('messenger_outbox')
            ->willReturn([
                'id' => new Column('id', new StringType()),
                'body' => new Column('body', new StringType()),
            ]);

        $connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->callback(function (string $sql): bool {
                $this->assertStringContainsString('ALTER TABLE messenger_outbox', $sql);
                $this->assertStringContainsString('transaction_id', $sql);
                $this->assertStringContainsString('xid8', $sql);
                $this->assertStringContainsString('pg_current_xact_id()', $sql);

                return true;
            }));

        $this->strategy->afterTableCreated($connection, 'messenger_outbox');
    }

    #[Test]
    public function itSkipsTransactionIdColumnWhenAlreadyExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $platform = $this->createStub(PostgreSQLPlatform::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($platform);

        /** @var AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform>&\PHPUnit\Framework\MockObject\MockObject $schemaManager */
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableColumns')
            ->with('messenger_outbox')
            ->willReturn([
                'id' => new Column('id', new StringType()),
                'transaction_id' => new Column('transaction_id', new StringType()),
            ]);

        $connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $connection->expects($this->never())
            ->method('executeStatement');

        $this->strategy->afterTableCreated($connection, 'messenger_outbox');
    }
}
