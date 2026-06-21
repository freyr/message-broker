<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;

final class DeduplicationCleanupTest extends FunctionalTestCase
{
    private PdoDeduplicationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PdoDeduplicationStore(self::$pdo, static::platform());
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
