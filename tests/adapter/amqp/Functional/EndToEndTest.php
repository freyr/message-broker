<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Functional;

use Freyr\MessageBroker\Adapter\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Adapter\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Adapter\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Adapter\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\BrokerHeader;
use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Testing\RecordingEvents;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RuntimeException;

/**
 * The slice 1 promise, verified end to end against real PostgreSQL and RabbitMQ:
 *
 *   produce → outbox → relay (ordered, confirmed) → exchange → queue →
 *   consumer (dedup) → dispatcher
 *
 * and the failure circle:
 *
 *   failing dispatch → TTL-wait-queue retries → exhaustion → database DLQ →
 *   replay (back through the outbox + relay) → fixed handler succeeds
 */
final class EndToEndTest extends AmqpFunctionalTestCase
{
    private const string EXCHANGE = 'mb_e2e';
    private const string QUEUE = 'mb_e2e_q';
    private const string QUEUE_B = 'mb_e2e_b_q'; // a second consumer group on the same routing key
    private const string LANE = 'e2e';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel;        // consumer + topology
    private AMQPChannel $relayChannel;   // confirms are channel-global: the relay owns its channel
    private OutboxStore $outbox;
    private OutboxProducer $producer;
    private PostgreSqlDeadLetterStore $deadLetters;
    private Relay $relay; // one long-lived relay per channel, as in production

    private bool $handlerFails = false;

    /** The count of the next handler calls that fail. */
    private int $failuresLeft = 0;

    private int $handlerAttempts = 0;

    /** @var list<IncomingMessage> */
    private array $dispatched = [];

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
        $channel->queue_delete(self::isolated(self::QUEUE));
        $channel->queue_delete(self::isolated(self::QUEUE).'.wait.100');
        $channel->queue_delete(self::isolated(self::QUEUE_B));
        $channel->exchange_delete(self::isolated(self::EXCHANGE));
        $channel->close();
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatched = [];
        $this->handlerFails = false;
        $this->failuresLeft = 0;
        $this->handlerAttempts = 0;

        $this->channel = self::$amqp->channel();
        $this->relayChannel = self::$amqp->channel();
        $this->channel->exchange_declare(self::isolated(self::EXCHANGE), 'topic', false, true, false);
        $this->channel->queue_declare(self::isolated(self::QUEUE), false, true, false, false);
        $this->channel->queue_bind(self::isolated(self::QUEUE), self::isolated(self::EXCHANGE), 'order.*');
        $this->channel->queue_purge(self::isolated(self::QUEUE));
        $this->channel->queue_declare(self::isolated(self::QUEUE_B), false, true, false, false);
        $this->channel->queue_bind(self::isolated(self::QUEUE_B), self::isolated(self::EXCHANGE), 'order.*');
        $this->channel->queue_purge(self::isolated(self::QUEUE_B));

        $this->outbox = new PostgreSqlOutboxStore(self::$pdo);
        $this->producer = new OutboxProducer($this->outbox, new JsonWireFormat(), lane: self::LANE);
        $this->deadLetters = new PostgreSqlDeadLetterStore(self::$pdo);
        $this->relay = new Relay(
            outbox: $this->outbox,
            publisher: new AmqpMessagePublisher(
                $this->relayChannel,
                new AmqpPublishConfig(exchange: self::isolated(self::EXCHANGE)),
                JsonWireFormat::CONTENT_TYPE,
            ),
            lane: self::LANE,
        );
    }

    protected function tearDown(): void
    {
        $this->channel->close();
        $this->relayChannel->close();
    }

    private function consumer(
        ?BrokerEvents $events = null,
        string $queue = self::QUEUE,
        string $name = 'e2e_consumer',
    ): AmqpConsumer {
        $dispatch = function (IncomingMessage $incoming): void {
            ++$this->handlerAttempts;
            if ($this->handlerFails) {
                throw new RuntimeException('temporary downstream outage');
            }
            if ($this->failuresLeft > 0) {
                --$this->failuresLeft;

                throw new RuntimeException('one more outage');
            }
            $this->dispatched[] = $incoming;
        };

        return new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::isolated($queue)),
            processor: new DeliveryProcessor(
                deserializer: new JsonDeserializer(),
                dispatcher: new CallableDispatcher($dispatch),
                transaction: new PdoTransaction(self::$pdo),
                deduplication: new PostgreSqlDeduplicationStore(self::$pdo),
                retryPolicy: new ExponentialRetryPolicy(
                    maxAttempts: 2,
                    backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
                ),
                deadLetters: $this->deadLetters,
                name: $name,
                events: $events,
            ),
        );
    }

    public function testHappyPathFromProduceToDispatcher(): void
    {
        $message = OrderPlaced::create('o-42', 12_500);

        self::$pdo->beginTransaction();
        $this->producer->produce($message, headers: [
            'correlation_id' => 'corr-7',
        ]);
        self::$pdo->commit();

        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched);
        self::assertSame('o-42', $this->dispatched[0]->payload['order_id']);
        self::assertSame(12_500, $this->dispatched[0]->payload['total_cents']);
        self::assertSame($message->id, $this->dispatched[0]->messageId);
        self::assertSame($message->createdAt, $this->dispatched[0]->createdAt);
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'), 'outbox drained');
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    public function testFailureCircleRetryDlqReplayRecovery(): void
    {
        $message = OrderPlaced::create('o-911', 100);
        $this->producer->produce($message);
        self::assertSame(1, $this->relay->drainOnce());

        // Handler is broken: attempt 1 → 100ms wait queue → attempt 2 → DLQ.
        $this->handlerFails = true;
        $this->consumer()
            ->run(messageLimit: 2, idleTimeoutSec: 10);

        self::assertSame(2, $this->handlerAttempts);
        self::assertCount(0, $this->dispatched);
        $deadLetters = $this->deadLetters->list(new DeadLetterFilter());
        self::assertCount(1, $deadLetters);
        self::assertSame($message->id, $deadLetters[0]->messageId);

        // Downstream recovered: replay rides the outbox + relay again.
        $this->handlerFails = false;
        $replay = new ReplayService($this->deadLetters, $this->outbox, new PdoTransaction(self::$pdo), new JsonWireFormat());
        $replay->replay($deadLetters[0]->id, lane: self::LANE);

        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched, 'replayed message must reach the fixed dispatcher');
        self::assertSame($message->id, $this->dispatched[0]->messageId);
        $replayed = $this->deadLetters->find($deadLetters[0]->id);
        self::assertNotNull($replayed);
        self::assertNotNull($replayed->replayedAt, 'dead letter kept for audit, marked replayed');
    }

    public function testDispatchFiresDispatchedEvent(): void
    {
        $events = new RecordingEvents();
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        self::assertSame(1, $this->relay->drainOnce());

        $this->consumer($events)
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched);
        self::assertContains(BrokerEvents::DISPATCHED, $events->names());
    }

    public function testDuplicateDeliveryFiresDeduplicatedEvent(): void
    {
        $events = new RecordingEvents();
        $message = OrderPlaced::create('o-dup', 100);

        // Pre-seed dedup as if this consumer already processed the message.
        (new PostgreSqlDeduplicationStore(self::$pdo))->acquire(
            new IncomingMessage($message->id, $message->name, $message->createdAt, []),
            'e2e_consumer',
        );

        $this->producer->produce($message);
        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer($events)
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(0, $this->dispatched, 'duplicate is not dispatched');
        self::assertContains(BrokerEvents::DEDUPLICATED, $events->names());
    }

    public function testDeadLetterFiresDeadLetteredEvent(): void
    {
        $events = new RecordingEvents();
        $this->producer->produce(OrderPlaced::create('o-dlq', 100));
        self::assertSame(1, $this->relay->drainOnce());

        $this->handlerFails = true;
        $this->consumer($events)
            ->run(messageLimit: 2, idleTimeoutSec: 10);

        self::assertContains(BrokerEvents::DEAD_LETTERED, $events->names());
    }

    public function testAReplayReachesTwoQueuesAndOnlyTheSourceQueueProcessesIt(): void
    {
        $message = OrderPlaced::create('o-two-queues', 100);
        $deadLetter = $this->deadLetterOfQueueA($message);
        $this->channel->queue_purge(self::isolated(self::QUEUE_B)); // B processed the original message

        $this->replayService()->replay($deadLetter->id, lane: self::LANE);
        self::assertSame(1, $this->relay->drainOnce());

        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);
        self::assertCount(1, $this->dispatched, 'consumer A processes the replay');
        self::assertSame($message->id, $this->dispatched[0]->messageId);

        $attemptsBefore = $this->handlerAttempts;
        $eventsB = new RecordingEvents();
        $this->consumer($eventsB, self::QUEUE_B, 'e2e_consumer_b')
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertSame($attemptsBefore, $this->handlerAttempts, 'consumer B calls no handler');
        self::assertSame([BrokerEvents::REPLAY_SKIPPED], $eventsB->names());
        self::assertSame([
            'consumer' => 'e2e_consumer_b',
            'source' => self::isolated(self::QUEUE_B),
            'target' => self::isolated(self::QUEUE),
            'message_id' => $message->id,
            'message_name' => 'order.placed',
        ], $eventsB->records[0]['context']);
        self::assertSame(0, self::fetchInt("SELECT COUNT(*) FROM message_deduplication WHERE consumer = 'e2e_consumer_b'"));
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM message_deduplication WHERE consumer = 'e2e_consumer'"));
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM dead_letters'), 'B writes no dead letter');
        self::assertSame(0, $this->messagesIn(self::QUEUE_B), 'B acked the replay');
    }

    public function testAReplayThatFailsOnceKeepsTheTargetThroughTheWaitQueue(): void
    {
        $message = OrderPlaced::create('o-retry-replay', 100);
        $deadLetter = $this->deadLetterOfQueueA($message);

        $this->replayService()->replay($deadLetter->id, lane: self::LANE);
        self::assertSame(1, $this->relay->drainOnce());
        $this->failuresLeft = 1;
        $this->consumer()
            ->run(messageLimit: 2, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched, 'the second delivery is processed');
        self::assertSame(self::isolated(self::QUEUE), $this->dispatched[0]->headers[BrokerHeader::REPLAY_TARGET] ?? null);
        self::assertSame(2, $this->dispatched[0]->headers['x-attempt'] ?? null, 'it passed the wait queue');
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }

    public function testAStaleDeliveryCountOfAQuorumQueueDeadLetterIsNotADeliveryLimitDeadLetter(): void
    {
        // A dead letter as a consumer of a quorum queue stores it: the broker
        // header x-delivery-count is in its headers, above the default limit of 5.
        $message = OrderPlaced::create('o-quorum', 100);
        $deadLetter = DeadLetter::fromFailure(
            source: self::isolated(self::QUEUE),
            messageId: $message->id,
            messageName: $message->name,
            body: (string) json_encode([
                'metadata' => [
                    'message_name' => $message->name,
                    'message_id' => $message->id,
                    'created_at' => $message->createdAt,
                ],
                'payload' => $message->payload(),
            ]),
            headers: [
                'x-delivery-count' => 7,
                'x-attempt' => 2,
            ],
            error: new RuntimeException('handler failed'),
            attempts: 2,
        );
        $this->deadLetters->store($deadLetter);

        $this->replayService()->replay($deadLetter->id, lane: self::LANE);
        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched, 'the first delivery of the replay is processed');
        self::assertArrayNotHasKey('x-delivery-count', $this->dispatched[0]->headers);
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM dead_letters'), 'no delivery limit dead letter');
    }

    /** The message fails twice on queue A (attempt 1, the wait queue, attempt 2) and becomes a dead letter. */
    private function deadLetterOfQueueA(OrderPlaced $message): DeadLetter
    {
        $this->producer->produce($message);
        self::assertSame(1, $this->relay->drainOnce());
        $this->handlerFails = true;
        $this->consumer()
            ->run(messageLimit: 2, idleTimeoutSec: 10);
        $this->handlerFails = false;
        $this->handlerAttempts = 0;
        // A new consumer puts its channel in confirm mode again: give it a new channel.
        $this->channel->close();
        $this->channel = self::$amqp->channel();

        $deadLetters = $this->deadLetters->list(new DeadLetterFilter());
        self::assertCount(1, $deadLetters);
        self::assertSame(self::isolated(self::QUEUE), $deadLetters[0]->source);

        return $deadLetters[0];
    }

    private function replayService(): ReplayService
    {
        return new ReplayService($this->deadLetters, $this->outbox, new PdoTransaction(self::$pdo), new JsonWireFormat());
    }

    private function messagesIn(string $queue): int
    {
        [, $count] = $this->channel->queue_declare(self::isolated($queue), true);

        return (int) $count;
    }
}
