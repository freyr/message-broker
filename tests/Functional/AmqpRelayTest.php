<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Fixtures\RecordingErrorHandler;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

final class AmqpRelayTest extends FunctionalTestCase
{
    private const string EXCHANGE = 'mb_relay_test';
    private const string QUEUE = 'mb_relay_test_q';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel;
    private OutboxStore $store;
    private OutboxProducer $producer;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$amqp = new AMQPStreamConnection(
            host: getenv('AMQP_HOST') ?: 'rabbitmq',
            port: (int) (getenv('AMQP_PORT') ?: 5672),
            user: getenv('AMQP_USER') ?: 'guest',
            password: getenv('AMQP_PASSWORD') ?: 'guest',
            vhost: getenv('AMQP_VHOST') ?: '/',
        );
    }

    public static function tearDownAfterClass(): void
    {
        $channel = self::$amqp->channel();
        $channel->queue_delete(self::QUEUE);
        $channel->exchange_delete(self::EXCHANGE);
        $channel->close();
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = self::$amqp->channel();
        // RabbitMQ 4.x deprecates transient entities — declare durable, drop in teardown.
        $this->channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $this->channel->queue_declare(self::QUEUE, false, true, false, false);
        $this->channel->queue_bind(self::QUEUE, self::EXCHANGE, '#');
        $this->channel->queue_purge(self::QUEUE);

        $this->store = new OutboxStore(self::$pdo, static::platform());
        $this->producer = new OutboxProducer($this->store, new JsonWireFormat(), lane: 'orders');
    }

    protected function tearDown(): void
    {
        $this->channel->close();
    }

    private function relay(): AmqpRelay
    {
        return new AmqpRelay(
            outbox: $this->store,
            amqp: $this->channel,
            publish: new AmqpPublishConfig(exchange: self::EXCHANGE),
            contentType: JsonWireFormat::CONTENT_TYPE,
            lane: 'orders',
        );
    }

    public function testDrainPublishesAllPendingRowsInOrderAndEmptiesTheOutbox(): void
    {
        $first = OrderPlaced::create('o-1', 100);
        $second = OrderPlaced::create('o-1', 200);
        $third = OrderPlaced::create('o-1', 300);
        $this->producer->produce($first);
        $this->producer->produce($second);
        $this->producer->produce($third);

        $published = $this->relay()
            ->drainOnce();

        self::assertSame(3, $published);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());

        $received = [];
        while (($delivery = $this->channel->basic_get(self::QUEUE, no_ack: true)) !== null) {
            $received[] = $delivery;
        }

        self::assertCount(3, $received);
        self::assertSame(
            [$first->id, $second->id, $third->id],
            array_map(static fn ($d): string => (string) $d->get_properties()['message_id'], $received),
            'messages must arrive in production order',
        );
        self::assertSame('order.placed', $received[0]->getRoutingKey(), 'routing key derives from message_name');
        self::assertSame('application/json', $received[0]->get_properties()['content_type']);
        self::assertSame($first->id, $received[0]->get_properties()['message_id']);
    }

    public function testBackedOffHeadBlocksItsWholeLane(): void
    {
        $head = OrderPlaced::create('o-1', 100);
        $next = OrderPlaced::create('o-1', 200);
        $this->producer->produce($head);
        $this->producer->produce($next);

        // Simulate a failed publish backing off the head: available_at in the future.
        $oneHourAhead = EpochMillis::toDateTime(EpochMillis::now() + 3_600_000)->format('Y-m-d H:i:s.v');
        $statement = self::$pdo->prepare(
            'UPDATE outbox_messages SET available_at = :available_at, attempts = 1 WHERE id = :id',
        );
        $statement->execute([
            'available_at' => $oneHourAhead,
            'id' => $head->id,
        ]);

        $published = $this->relay()
            ->drainOnce();

        self::assertSame(0, $published, 'nothing may overtake a backing-off head (D17)');
        self::assertSame(2, (int) self::$pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
        self::assertNull($this->channel->basic_get(self::QUEUE, no_ack: true));
    }

    public function testPublishFailureBacksOffHeadInvokesErrorHandlerAndBlocksLane(): void
    {
        $head = OrderPlaced::create('o-1', 100);
        $next = OrderPlaced::create('o-1', 200);
        $this->producer->produce($head);
        $this->producer->produce($next);

        $errorHandler = new RecordingErrorHandler();
        $badChannel = self::$amqp->channel();
        $failing = new AmqpRelay(
            outbox: $this->store,
            amqp: $badChannel,
            publish: new AmqpPublishConfig(exchange: 'missing_exchange_404'),
            contentType: JsonWireFormat::CONTENT_TYPE,
            lane: 'orders',
            backoff: Backoff::exponential(initialDelayMs: 60_000, maxDelayMs: 600_000),
            errorHandler: $errorHandler,
        );

        self::assertSame(0, $failing->drainOnce());

        // Nothing left the outbox; only the head was backed off.
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
        $now = EpochMillis::toDateTime(EpochMillis::now())->format('Y-m-d H:i:s.v');
        $backedOff = self::$pdo->prepare(
            'SELECT COUNT(*) FROM outbox_messages
             WHERE id = :id AND attempts = 1 AND available_at > :now',
        );
        $backedOff->execute([
            'id' => $head->id,
            'now' => $now,
        ]);
        self::assertSame(
            1,
            (int) $backedOff->fetchColumn(),
            'head must be backed off into the future with attempts incremented',
        );
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE id = '{$next->id}' AND attempts = 0"),
            'rows behind the head stay untouched',
        );

        self::assertCount(1, $errorHandler->calls);
        self::assertSame($head->id, $errorHandler->calls[0]['context']['message_id']);
        self::assertSame(1, $errorHandler->calls[0]['context']['attempt']);

        // A healthy relay still cannot overtake the backing-off head (D17).
        self::assertSame(0, $this->relay()->drainOnce());
    }

    public function testDrainTouchesOnlyItsOwnLane(): void
    {
        $other = new OutboxProducer($this->store, new JsonWireFormat(), lane: 'notifications');
        $other->produce(OrderPlaced::create('o-1', 100));

        $published = $this->relay()
            ->drainOnce();

        self::assertSame(0, $published);
        self::assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }
}
