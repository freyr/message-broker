<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Unit;

use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PostgreSqlDeduplicationStoreTest extends TestCase
{
    public function testTheInsertStatementIsPreparedOncePerStore(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('rowCount')->willReturnOnConsecutiveCalls(1, 0);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->with(self::stringContains('ON CONFLICT (message_id, consumer) DO NOTHING'))->willReturn($statement);

        $store = new PostgreSqlDeduplicationStore($pdo);
        $message = new IncomingMessage('m-1', 'order.placed', 1, []);

        self::assertTrue($store->acquire($message, 'c'));
        self::assertFalse($store->acquire($message, 'c'));
    }
}
