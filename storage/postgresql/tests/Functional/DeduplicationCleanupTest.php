<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Time\EpochMillis;

final class DeduplicationCleanupTest extends FunctionalTestCase
{
    private PostgreSqlDeduplicationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PostgreSqlDeduplicationStore(self::$pdo);
    }

    public function testCleanupRemovesOnlyEntriesCreatedBeforeThreshold(): void
    {
        $this->store->acquire($this->incoming('m-old'), 'orders_consumer');
        $this->store->acquire($this->incoming('m-fresh'), 'orders_consumer');
        $eightDaysAgo = EpochMillis::toDateTime(EpochMillis::now() - 8 * 86_400_000)->format('Y-m-d H:i:s.v');
        $aged = self::$pdo->prepare('UPDATE message_deduplication SET created_at = :ts WHERE message_id = :id');
        $aged->execute([
            'ts' => $eightDaysAgo,
            'id' => 'm-old',
        ]);

        $removed = $this->store->cleanup(beforeEpochMs: EpochMillis::now() - 7 * 86_400_000);

        self::assertSame(1, $removed);
        self::assertFalse(
            $this->store->acquire($this->incoming('m-fresh'), 'orders_consumer'),
            'fresh entry must survive cleanup',
        );
        self::assertTrue(
            $this->store->acquire($this->incoming('m-old'), 'orders_consumer'),
            'old entry must be gone',
        );
    }

    public function testCleanupDeletesInChunksUntilNoOldEntryIsLeft(): void
    {
        $store = new PostgreSqlDeduplicationStore(self::$pdo, deleteChunkSize: 2);
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $store->acquire($this->incoming("m-{$id}"), 'orders_consumer');
        }
        $store->acquire($this->incoming('m-fresh'), 'orders_consumer');
        self::$pdo->exec("UPDATE message_deduplication SET created_at = '2000-01-01 00:00:00' WHERE message_id <> 'm-fresh'");

        self::assertSame(5, $store->cleanup(beforeEpochMs: EpochMillis::now() - 86_400_000), 'three chunks: 2 + 2 + 1');
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM message_deduplication'));
    }

    private function incoming(string $messageId): IncomingMessage
    {
        return new IncomingMessage(
            messageId: $messageId,
            messageName: 'order.placed',
            createdAt: EpochMillis::now(),
            payload: [],
        );
    }
}
