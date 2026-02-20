<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\DeduplicationDbalStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit test for DeduplicationDbalStore.
 *
 * Tests that the store:
 * - Returns false (not duplicate) when insert succeeds
 * - Returns true (duplicate) when UniqueConstraintViolationException is thrown
 * - Logs duplicate detection at info level
 * - Uses custom table name in insert call
 */
#[CoversClass(DeduplicationDbalStore::class)]
final class DeduplicationDbalStoreTest extends TestCase
{
    public function testReturnsFalseWhenInsertSucceeds(): void
    {
        $messageId = Id::new();

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('insert')
            ->with(
                'message_broker_deduplication',
                $this->callback(function (array $data) use ($messageId): bool {
                    $this->assertSame($messageId->toBinary(), $data['message_id']);
                    $this->assertSame('App\\Message\\OrderPlaced', $data['message_name']);
                    $this->assertArrayHasKey('processed_at', $data);

                    return true;
                })
            );

        $store = new DeduplicationDbalStore($connection);

        $this->assertFalse($store->isDuplicate($messageId, 'App\\Message\\OrderPlaced'));
    }

    public function testReturnsTrueWhenUniqueConstraintViolated(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('insert')
            ->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        $store = new DeduplicationDbalStore($connection);

        $this->assertTrue($store->isDuplicate(Id::new(), 'App\\Message\\OrderPlaced'));
    }

    public function testLogsDuplicateDetection(): void
    {
        $messageId = Id::new();

        $connection = $this->createStub(Connection::class);
        $connection->method('insert')
            ->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'Duplicate message detected by deduplication store',
                $this->callback(function (array $context) use ($messageId): bool {
                    $this->assertSame((string) $messageId, $context['message_id']);
                    $this->assertSame('App\\Message\\OrderPlaced', $context['message_name']);

                    return true;
                })
            );

        $store = new DeduplicationDbalStore($connection, 'message_broker_deduplication', $logger);

        $store->isDuplicate($messageId, 'App\\Message\\OrderPlaced');
    }

    public function testUsesCustomTableName(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('insert')
            ->with('custom_dedup_table', $this->anything());

        $store = new DeduplicationDbalStore($connection, 'custom_dedup_table');

        $store->isDuplicate(Id::new(), 'App\\Message\\OrderPlaced');
    }
}
