<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PublishedRows;
use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Time\EpochMillis;

/**
 * PublishedRows::Delete: the drain deletes each published row in the claim
 * transaction; a retried row and an older published row with the same id
 * stay.
 */
final class OutboxDeleteOnPublishTest extends FunctionalTestCase
{
    private const string LANE = 'delete-on-publish';

    private PostgreSqlOutboxStore $store;
    private OutboxProducer $producer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PostgreSqlOutboxStore(self::$pdo, PublishedRows::Delete);
        $this->producer = new OutboxProducer(new PostgreSqlOutboxStore(self::$pdo), new JsonWireFormat(), lane: self::LANE);
    }

    /** @return list<string> */
    private static function ids(array $claimed): array
    {
        return array_map(static fn (OutboxRecord $record): string => $record->id, $claimed);
    }

    public function testTheDefaultIsMark(): void
    {
        $this->producer->produce(OrderPlaced::create('o-1', 100));

        (new PostgreSqlOutboxStore(self::$pdo))->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NOT NULL'));
    }

    public function testPublishedRowsAreDeletedAndRetriedRowsStayWithOneMoreAttempt(): void
    {
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        $this->producer->produce(OrderPlaced::create('o-2', 200));
        $this->producer->produce(OrderPlaced::create('o-3', 300));
        $retried = null;

        $published = $this->store->drainClaimed(
            self::LANE,
            10,
            static function (array $claimed) use (&$retried): ClaimOutcome {
                $ids = self::ids($claimed);
                $retried = array_pop($ids);

                return new ClaimOutcome($ids, [
                    $retried => EpochMillis::now() + 60_000,
                ]);
            },
        );

        self::assertSame(2, $published);
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'only the retried row stays');
        self::assertSame(1, self::fetchInt("SELECT attempts FROM outbox_messages WHERE id = '{$retried}'"));
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NOT NULL'), 'no row is marked');
    }

    public function testAReplayOfAMessageThatAMarkStorePublishedLeavesTheOriginalRow(): void
    {
        $message = OrderPlaced::create('o-1', 100);
        $this->producer->produce($message);
        (new PostgreSqlOutboxStore(self::$pdo))->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );
        $this->store->insert(new OutboxRecord(
            id: $message->id,
            lane: self::LANE,
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: $message->createdAt + 1, // distinct primary key, in the range of the claim
            availableAt: EpochMillis::now() - 1_000,
        ));

        $published = $this->store->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => ClaimOutcome::published(self::ids($claimed)),
        );

        self::assertSame(1, $published);
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'the replay row is deleted');
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE id = '{$message->id}' AND published_at IS NOT NULL"), 'the original row keeps its mark');
    }

}
