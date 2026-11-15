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
use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Testing\RecordingEvents;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Proves spec decision D-C6: consumers need NO code changes for parallel
 * consumption. AMQP's competing-consumer guarantee already lets N workers
 * drain one queue; a shared dedup scope (same `name`) already keeps
 * processing exactly-once when more than one worker feeds it. This test
 * pins that behavior — it does not exercise any new consumer code.
 */
final class ParallelConsumptionTest extends AmqpFunctionalTestCase
{
    private const string EXCHANGE = 'mb_parallel_c';
    private const string QUEUE = 'mb_parallel_c_q';
    private const string LANE = 'parallel';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel;        // topology only; consumers/relay own their channels
    private AMQPChannel $relayChannel;   // confirms are channel-global: the relay owns its channel
    private OutboxStore $outbox;
    private OutboxProducer $producer;
    private Relay $relay; // one long-lived relay per channel, as in production
    private ?AMQPChannel $workerChannel = null; // the most recently started worker's own channel

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
        $channel->exchange_delete(self::isolated(self::EXCHANGE));
        $channel->close();
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatched = [];
        $this->workerChannel = null;

        $this->channel = self::$amqp->channel();
        $this->relayChannel = self::$amqp->channel();
        $this->channel->exchange_declare(self::isolated(self::EXCHANGE), 'topic', false, true, false);
        $this->channel->queue_declare(self::isolated(self::QUEUE), false, true, false, false);
        $this->channel->queue_bind(self::isolated(self::QUEUE), self::isolated(self::EXCHANGE), 'order.*');
        $this->channel->queue_purge(self::isolated(self::QUEUE));

        $this->outbox = new PostgreSqlOutboxStore(self::$pdo);
        $this->producer = new OutboxProducer($this->outbox, new JsonWireFormat(), lane: self::LANE);
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
        $this->workerChannel?->close();
    }

    /**
     * Two consumer instances share ONE dedup scope (name: 'parallel') but own
     * separate channels — two workers of the same logical consumer. Each new
     * worker closes the previous worker's channel first: a real worker
     * process exits (closing its connection/channel) once its run finishes,
     * which is what returns any message the broker already pushed onto that
     * channel's prefetch buffer — but never got to hand off to the
     * dispatcher before messageLimit was hit — back to the queue for the
     * next worker. AmqpConsumer::run()'s own docblock documents this
     * requeue-on-close contract.
     */
    private function consumer(?BrokerEvents $events = null): AmqpConsumer
    {
        $this->workerChannel?->close();
        $this->workerChannel = self::$amqp->channel();

        return new AmqpConsumer(
            channel: $this->workerChannel,
            queue: new AmqpQueueConfig(queue: self::isolated(self::QUEUE), prefetch: 1),
            processor: new DeliveryProcessor(
                deserializer: new JsonDeserializer(),
                dispatcher: new CallableDispatcher(function (IncomingMessage $incoming): void {
                    $this->dispatched[] = $incoming;
                }),
                transaction: new PdoTransaction(self::$pdo),
                deduplication: new PostgreSqlDeduplicationStore(self::$pdo),
                retryPolicy: new ExponentialRetryPolicy(),
                deadLetters: new PostgreSqlDeadLetterStore(self::$pdo),
                name: 'parallel', // ONE dedup scope shared by both workers
                events: $events,
            ),
        );
    }

    public function testTwoWorkersSplitOneQueueAndEachMessageIsDispatchedOnce(): void
    {
        $ids = [];
        foreach (range(1, 4) as $i) {
            $message = OrderPlaced::create("o-{$i}", $i * 100);
            $ids[] = $message->id;
            $this->producer->produce($message);
        }
        $this->relay->drainOnce();

        // Two workers of the same logical consumer take turns on the queue.
        // (True cross-process concurrency is AMQP's competing-consumer
        // guarantee; what the library must prove is that a shared dedup
        // scope stays correct when more than one worker feeds it.)
        $this->consumer()
            ->run(messageLimit: 2, idleTimeoutSec: 2);
        $this->consumer()
            ->run(messageLimit: 2, idleTimeoutSec: 2);

        $dispatchedIds = array_map(static fn (IncomingMessage $m): string => $m->messageId, $this->dispatched);
        sort($dispatchedIds);
        sort($ids);
        self::assertSame($ids, $dispatchedIds, 'each message dispatched exactly once across workers');
        self::assertSame(4, self::fetchInt('SELECT COUNT(*) FROM message_deduplication'));
    }

    public function testWireDuplicateLandingOnASecondWorkerIsAbsorbed(): void
    {
        // The duplicate a relay crash produces: the message reached the broker
        // but the claim never committed, so the row is still unpublished and
        // the next drain publishes it again.
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        $this->relay->drainOnce();
        self::$pdo->exec('UPDATE outbox_messages SET published_at = NULL');
        $this->relay->drainOnce();

        $events = new RecordingEvents();
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 2);          // worker A dispatches
        $this->consumer($events)
            ->run(messageLimit: 1, idleTimeoutSec: 2);   // worker B gets the duplicate

        self::assertCount(1, $this->dispatched, 'the duplicate must not dispatch');
        self::assertContains('message.deduplicated', $events->names(), 'worker B absorbed it via dedup');
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
    }
}
