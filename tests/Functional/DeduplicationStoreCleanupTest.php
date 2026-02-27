<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Command\DeduplicationStoreCleanup;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional test for DeduplicationStoreCleanup command against real MySQL.
 *
 * Verifies that the DATE_SUB SQL works correctly with binary UUID v7 schema,
 * deleting old rows and keeping recent ones.
 */
#[CoversClass(DeduplicationStoreCleanup::class)]
final class DeduplicationStoreCleanupTest extends FunctionalDatabaseTestCase
{
    public function testOldRecordsAreDeletedAndRecentKept(): void
    {
        self::$connection->insert('message_broker_deduplication', [
            'message_id' => Id::new()->toBinary(),
            'message_name' => 'old.event',
            'processed_at' => '2000-01-01 00:00:00',
        ]);

        self::$connection->insert('message_broker_deduplication', [
            'message_id' => Id::new()->toBinary(),
            'message_name' => 'recent.event',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        $command = new DeduplicationStoreCleanup(self::$connection);
        $tester = new CommandTester($command);
        $tester->execute([
            '--days' => 30,
        ]);

        $count = self::$connection->fetchOne('SELECT COUNT(*) FROM message_broker_deduplication');
        $this->assertIsNumeric($count);

        $this->assertSame(1, (int) $count, 'Only recent record should remain');

        $remaining = self::$connection->fetchOne('SELECT message_name FROM message_broker_deduplication');

        $this->assertSame('recent.event', $remaining);
    }

    public function testOutputReportsDeletedCount(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            self::$connection->insert('message_broker_deduplication', [
                'message_id' => Id::new()->toBinary(),
                'message_name' => 'old.event.'.$i,
                'processed_at' => '2000-01-01 00:00:00',
            ]);
        }

        $command = new DeduplicationStoreCleanup(self::$connection);
        $tester = new CommandTester($command);
        $tester->execute([
            '--days' => 30,
        ]);

        $this->assertStringContainsString('Removed 3 old idempotency records', $tester->getDisplay());
    }
}
