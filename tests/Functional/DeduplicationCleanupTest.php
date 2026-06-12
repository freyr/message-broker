<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Consumer\PdoDeduplicationStore;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Time\EpochMillis;

final class DeduplicationCleanupTest extends FunctionalTestCase
{
    private PdoDeduplicationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PdoDeduplicationStore(self::$pdo, new MySqlPlatform());
    }

    public function testAcquireIsAtomicPerMessageAndConsumer(): void
    {
        $incoming = $this->incoming('m-1');

        self::assertTrue($this->store->acquire($incoming, 'orders_consumer'));
        self::assertFalse($this->store->acquire($incoming, 'orders_consumer'), 'duplicate must be rejected');
        self::assertTrue($this->store->acquire($incoming, 'other_consumer'), 'dedup is scoped per consumer');
    }

    public function testCleanupRemovesOnlyEntriesCreatedBeforeThreshold(): void
    {
        $this->store->acquire($this->incoming('m-old'), 'orders_consumer');
        $this->store->acquire($this->incoming('m-fresh'), 'orders_consumer');
        self::$pdo->exec(
            "UPDATE message_deduplication SET created_at = DATE_SUB(NOW(3), INTERVAL 8 DAY) WHERE message_id = 'm-old'",
        );

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
