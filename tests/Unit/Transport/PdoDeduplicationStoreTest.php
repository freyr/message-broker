<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PdoDeduplicationStoreTest extends TestCase
{
    public function testTheInsertStatementIsPreparedOncePerStore(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('rowCount')->willReturnOnConsecutiveCalls(1, 0);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->with('INSERT dedup')->willReturn($statement);
        $platform = $this->createStub(Platform::class);
        $platform->method('insertDeduplicationSql')->willReturn('INSERT dedup');

        $store = new PdoDeduplicationStore($pdo, $platform);
        $message = new IncomingMessage('m-1', 'order.placed', 1, []);

        self::assertTrue($store->acquire($message, 'c'));
        self::assertFalse($store->acquire($message, 'c'));
    }
}
