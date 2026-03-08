<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Freyr\MessageBroker\Outbox\Transport\MySqlOutboxPlatformStrategy;
use Freyr\MessageBroker\Outbox\Transport\OutboxPlatformStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MySqlOutboxPlatformStrategy::class)]
final class MySqlOutboxPlatformStrategyTest extends TestCase
{
    private MySqlOutboxPlatformStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new MySqlOutboxPlatformStrategy();
    }

    public function testItImplementsOutboxPlatformStrategy(): void
    {
        $this->assertInstanceOf(OutboxPlatformStrategy::class, $this->strategy);
    }

    public function testItDelegatesInsertToConnectionAndReturnsLastInsertId(): void
    {
        $connection = $this->createMock(Connection::class);

        $values = [
            'body' => '{"payload":"test"}',
            'queue_name' => 'outbox',
        ];
        $types = [
            'created_at' => Types::DATETIME_IMMUTABLE,
        ];

        $connection->expects($this->once())
            ->method('insert')
            ->with('messenger_outbox', $values, $types);

        $connection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('42');

        $id = $this->strategy->insertAndReturnId($connection, 'messenger_outbox', $values, $types);

        $this->assertSame('42', $id);
    }

    public function testItReturnsEmptyHeadOfLineFilter(): void
    {
        $this->assertSame('', $this->strategy->buildHeadOfLineFilter());
    }

    public function testItIsNoOpAfterTableCreated(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects($this->never())
            ->method('executeStatement');

        $this->strategy->afterTableCreated($connection, 'messenger_outbox');
    }
}
