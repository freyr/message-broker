<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Kafka;

use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Transport\Kafka\KafkaPublishConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaRelay;

final class KafkaRelayTest extends KafkaTestCase
{
    private OutboxStore $store;
    private OutboxProducer $producer;
    private string $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new OutboxStore(self::$pdo, static::platform());
        $this->producer = new OutboxProducer($this->store, new JsonWireFormat(), lane: 'orders');
        $this->topic = $this->uniqueTopic('mb_relay');
    }

    private function relay(): KafkaRelay
    {
        return new KafkaRelay(
            outbox: $this->store,
            publish: new KafkaPublishConfig(brokers: self::brokers(), topic: $this->topic),
            lane: 'orders',
        );
    }

    public function testDrainProducesAllRowsInPerKeyOrderAndEmptiesTheOutbox(): void
    {
        // Same key 'o-1' → same partition → strict order on read-back.
        $first = OrderPlaced::create('o-1', 100);
        $second = OrderPlaced::create('o-1', 200);
        $third = OrderPlaced::create('o-1', 300);
        $this->producer->produce($first);
        $this->producer->produce($second);
        $this->producer->produce($third);

        self::assertSame(3, $this->relay()->drainOnce());
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));

        $messages = $this->consumeAll($this->topic, max: 3);
        self::assertCount(3, $messages);

        self::assertSame(
            [$first->id, $second->id, $third->id],
            array_map(static fn ($m): string => $m->headers[MetadataHeader::MESSAGE_ID], $messages),
            'same-key messages must arrive in production order',
        );
        self::assertSame('o-1', $messages[0]->key, 'partition key is the message_key');
        self::assertSame('order.placed', $messages[0]->headers[MetadataHeader::MESSAGE_NAME]);
        // Body is the verbatim JSON wire bytes the producer wrote.
        self::assertJson((string) $messages[0]->payload);
    }

    public function testDrainTouchesOnlyItsOwnLane(): void
    {
        $other = new OutboxProducer($this->store, new JsonWireFormat(), lane: 'notifications');
        $other->produce(OrderPlaced::create('o-1', 100));

        self::assertSame(0, $this->relay()->drainOnce());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
    }

    public function testRelayRetriesAcquisitionAfterRivalReleasesAndReleasesOnShutdown(): void
    {
        // Flush any advisory-lock re-entrancy accumulated by self::$pdo in prior
        // tests: MySQL GET_LOCK and PG pg_try_advisory_lock both stack holds across
        // calls on the same connection, so earlier drainOnce() calls would block the
        // rival connection below until the session is fully cleared. Use query/fetchAll
        // to consume the result set; exec() on a SELECT leaves it unbuffered on MySQL.
        $flushSql = static::isPostgres() ? 'SELECT pg_advisory_unlock_all()' : 'SELECT RELEASE_ALL_LOCKS()';
        self::$pdo->query($flushSql)->fetchAll();

        // A rival on a separate connection holds the lane first.
        $rival = new OutboxStore(self::newConnection(), static::platform());
        self::assertTrue($rival->tryAcquireLane('orders'));

        $this->producer->produce(OrderPlaced::create('o-1', 100));

        // SAME relay instance: first drain can't acquire the contested lane…
        $relay = $this->relay();
        self::assertSame(0, $relay->drainOnce());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));

        // …the rival releases, and the SAME instance must retry and drain
        // (the old cached-false behavior would stay stuck at 0 forever).
        $rival->releaseLane('orders');
        self::assertSame(1, $relay->drainOnce());
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));

        // shutdown() frees the lane for a standby connection immediately.
        $standby = new OutboxStore(self::newConnection(), static::platform());
        self::assertFalse($standby->tryAcquireLane('orders'), 'relay still holds it before shutdown');
        $relay->shutdown();
        self::assertTrue($standby->tryAcquireLane('orders'), 'lane is free after shutdown');
    }
}
