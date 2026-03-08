<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\DeduplicationDbalStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional test for DeduplicationDbalStore against a real database (MySQL or PostgreSQL).
 *
 * Verifies that binary ULID INSERT and unique constraint
 * duplicate detection work correctly.
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

    #[Test]
    public function itReturnsNotDuplicateForNewMessage(): void
    {
        $result = $this->store->isDuplicate(Id::new(), 'test.event');

        $this->assertFalse($result, 'First insert of a new message should not be a duplicate');
    }

    #[Test]
    public function itDetectsDuplicateForSameMessageId(): void
    {
        $id = Id::new();
        $this->store->isDuplicate($id, 'test.event');

        $result = $this->store->isDuplicate($id, 'test.event');

        $this->assertTrue($result, 'Second insert with same message ID should be a duplicate');
    }

    #[Test]
    public function itReturnsNotDuplicateForDifferentMessageIds(): void
    {
        $this->assertFalse($this->store->isDuplicate(Id::new(), 'test.event'));
        $this->assertFalse($this->store->isDuplicate(Id::new(), 'test.event'));
    }

    #[Test]
    public function itPersistsRowWithCorrectData(): void
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

        $rawId = \is_resource($row['message_id']) ? stream_get_contents($row['message_id']) : $row['message_id'];
        $this->assertIsString($rawId);
        $restored = Id::fromBinary($rawId);
        $this->assertTrue($id->sameAs($restored), 'Binary ULID should round-trip correctly');
    }
}
