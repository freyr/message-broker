<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\DeduplicationDbalStore;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Functional test for DeduplicationDbalStore against real MySQL.
 *
 * Verifies that binary UUID v7 INSERT and unique constraint
 * duplicate detection work correctly with a real database.
 */
#[CoversClass(DeduplicationDbalStore::class)]
final class DeduplicationDbalStoreTest extends FunctionalDatabaseTestCase
{
    private DeduplicationDbalStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new DeduplicationDbalStore(self::$connection);
    }

    public function testNewMessageIsNotDuplicate(): void
    {
        $result = $this->store->isDuplicate(Id::new(), 'test.event');

        $this->assertFalse($result, 'First insert of a new message should not be a duplicate');
    }

    public function testSameMessageIdIsDuplicate(): void
    {
        $id = Id::new();
        $this->store->isDuplicate($id, 'test.event');

        $result = $this->store->isDuplicate($id, 'test.event');

        $this->assertTrue($result, 'Second insert with same message ID should be a duplicate');
    }

    public function testDifferentMessageIdsAreNotDuplicates(): void
    {
        $this->assertFalse($this->store->isDuplicate(Id::new(), 'test.event'));
        $this->assertFalse($this->store->isDuplicate(Id::new(), 'test.event'));
    }

    public function testRowIsPersistedWithCorrectData(): void
    {
        $id = Id::new();
        $messageName = 'order.placed';

        $this->store->isDuplicate($id, $messageName);

        $row = self::$connection->fetchAssociative(
            'SELECT message_id, message_name, processed_at FROM message_broker_deduplication WHERE message_id = ?',
            [$id->toBinary()],
            [\Doctrine\DBAL\ParameterType::BINARY]
        );

        $this->assertNotFalse($row, 'Row should exist in database');
        $this->assertSame($messageName, $row['message_name']);
        $this->assertNotEmpty($row['processed_at']);

        $this->assertIsString($row['message_id']);
        $restored = Id::fromBinary($row['message_id']);
        $this->assertTrue($id->sameAs($restored), 'Binary UUID v7 should round-trip correctly');
    }
}
