<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Kafka;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Transport\Kafka\KafkaConsumer;
use Freyr\MessageBroker\Transport\Kafka\KafkaConsumerConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaPublishConfig;
use Freyr\MessageBroker\Transport\Kafka\KafkaRelay;
use Freyr\MessageBroker\Transport\Kafka\KafkaRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use RuntimeException;

final class KafkaEndToEndTest extends KafkaTestCase
{
    private OutboxStore $store;
    private OutboxProducer $producer;
    private PdoDeadLetterStore $deadLetters;
    private string $topic;
    private string $group;

    /** @var list<IncomingMessage> */
    private array $dispatched = [];

    private ?\Closure $failingDispatch = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatched = [];
        $this->failingDispatch = null;
        $this->store = new PdoOutboxStore(self::$pdo, static::platform());
        $this->deadLetters = new PdoDeadLetterStore(self::$pdo, static::platform());
        $this->producer = new OutboxProducer($this->store, new JsonWireFormat(), lane: 'orders');
        $this->topic = $this->uniqueTopic('mb_e2e');
        $this->group = $this->uniqueGroup('mb_e2e');
    }

    private function relay(): KafkaRelay
    {
        return new KafkaRelay(
            outbox: $this->store,
            publish: new KafkaPublishConfig(brokers: self::brokers(), topic: $this->topic),
            lane: 'orders',
        );
    }

    private function consumer(int $maxAttempts = 5): KafkaConsumer
    {
        $dispatch = function (IncomingMessage $incoming): void {
            if ($this->failingDispatch !== null) {
                ($this->failingDispatch)($incoming);
            }
            $this->dispatched[] = $incoming;
        };

        return new KafkaConsumer(
            config: new KafkaConsumerConfig(brokers: self::brokers(), topic: $this->topic, groupId: $this->group),
            deserializer: new JsonDeserializer(),
            dispatcher: new CallableDispatcher($dispatch),
            pdo: self::$pdo,
            deduplication: new PdoDeduplicationStore(self::$pdo, static::platform()),
            retryPolicy: new KafkaRetryPolicy(
                maxAttempts: $maxAttempts,
                backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
            ),
            deadLetters: $this->deadLetters,
            name: 'orders_consumer',
        );
    }

    public function testRoundTripDispatchesAndRecordsDeduplication(): void
    {
        $message = OrderPlaced::create('o-77', 4_999);
        $this->producer->produce($message);
        self::assertSame(1, $this->relay()->drainOnce());

        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched);
        self::assertSame('o-77', $this->dispatched[0]->payload['order_id']);
        self::assertSame(4_999, $this->dispatched[0]->payload['total_cents']);
        self::assertSame($message->id, $this->dispatched[0]->messageId);
        self::assertSame('order.placed', $this->dispatched[0]->messageName);
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM message_deduplication WHERE message_id = '{$message->id}'"),
            'dedup entry committed with the handler',
        );
    }

    public function testDuplicateDeliveryIsSkipped(): void
    {
        $message = OrderPlaced::create('o-77', 4_999);
        $this->producer->produce($message);
        self::assertSame(1, $this->relay()->drainOnce());

        // Second physical delivery of the same message id (a relay re-publish).
        $this->store->insert(new \Freyr\MessageBroker\Outbox\OutboxRecord(
            id: $message->id,
            lane: 'orders',
            key: $message->key,
            metadata: [
                'message_name' => 'order.placed',
                'message_id' => $message->id,
                'created_at' => $message->createdAt,
            ],
            body: (string) json_encode([
                'order_id' => 'o-77',
                'total_cents' => 4_999,
            ]),
            headers: [],
            createdAt: $message->createdAt,
        ));
        self::assertSame(1, $this->relay()->drainOnce());

        $this->consumer()
            ->run(messageLimit: 2, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched, 'the second delivery of the same id must be skipped');
    }

    public function testMalformedBodyDeadLettersImmediately(): void
    {
        $this->store->insert(new \Freyr\MessageBroker\Outbox\OutboxRecord(
            id: 'm-bad',
            lane: 'orders',
            key: 'o-bad',
            metadata: [
                'message_name' => 'order.placed',
                'message_id' => 'm-bad',
                'created_at' => 1_749_722_400_123,
            ],
            body: '{{{not json',
            headers: [],
            createdAt: 1_749_722_400_123,
        ));
        self::assertSame(1, $this->relay()->drainOnce());

        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(0, $this->dispatched);
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE source = '{$this->topic}'"));
    }

    public function testDispatchExceptionExhaustsRetryBudgetAndDeadLetters(): void
    {
        $attempts = 0;
        $this->failingDispatch = static function () use (&$attempts): void {
            ++$attempts;
            throw new RuntimeException('handler always fails');
        };

        $message = OrderPlaced::create('o-77', 4_999);
        $this->producer->produce($message);
        self::assertSame(1, $this->relay()->drainOnce());

        $this->consumer(maxAttempts: 2)
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertSame(2, $attempts, 'handler attempted exactly maxAttempts times');
        self::assertCount(0, $this->dispatched);
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE message_id = '{$message->id}' AND attempts = 2"),
        );
        self::assertSame(
            0,
            self::fetchInt("SELECT COUNT(*) FROM message_deduplication WHERE message_id = '{$message->id}'"),
            'failed handling must not leave a dedup entry (atomic rollback)',
        );
    }
}
