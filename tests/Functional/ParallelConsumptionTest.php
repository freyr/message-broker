<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Fixtures\RecordingEvents;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Proves spec decision D-C6: consumers need NO code changes for parallel
 * consumption. AMQP's competing-consumer guarantee already lets N workers
 * drain one queue; a shared dedup scope (same `name`) already keeps
 * processing exactly-once when more than one worker feeds it. This test
 * pins that behavior — it does not exercise any new consumer code.
 */
final class ParallelConsumptionTest extends FunctionalTestCase
{
    private const string EXCHANGE = 'mb_parallel_c';
    private const string QUEUE = 'mb_parallel_c_q';
    private const string LANE = 'parallel';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel;        // topology only; consumers/relay own their channels
    private AMQPChannel $relayChannel;   // confirms are channel-global: the relay owns its channel
    private OutboxStore $outbox;
    private OutboxProducer $producer;
    private AmqpRelay $relay; // one long-lived relay per channel, as in production
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
        $channel->queue_delete(self::QUEUE);
        $channel->exchange_delete(self::EXCHANGE);
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
        $this->channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $this->channel->queue_declare(self::QUEUE, false, true, false, false);
        $this->channel->queue_bind(self::QUEUE, self::EXCHANGE, 'order.*');
        $this->channel->queue_purge(self::QUEUE);

        $this->outbox = new PdoOutboxStore(self::$pdo, static::platform());
        $this->producer = new OutboxProducer($this->outbox, new JsonWireFormat(), lane: self::LANE);
        $this->relay = new AmqpRelay(
            outbox: $this->outbox,
            amqp: $this->relayChannel,
            publish: new AmqpPublishConfig(exchange: self::EXCHANGE),
            contentType: JsonWireFormat::CONTENT_TYPE,
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
            queue: new AmqpQueueConfig(queue: self::QUEUE, prefetch: 1),
            deserializer: new JsonDeserializer(),
            dispatcher: new CallableDispatcher(function (IncomingMessage $incoming): void {
                $this->dispatched[] = $incoming;
            }),
            pdo: self::$pdo,
            deduplication: new PdoDeduplicationStore(self::$pdo, static::platform()),
            retryPolicy: new AmqpRetryPolicy(),
            deadLetters: new PdoDeadLetterStore(self::$pdo, static::platform()),
            name: 'parallel', // ONE dedup scope shared by both workers
            events: $events,
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
        // The duplicate a competing-relay crash produces: the same message
        // published twice. Capture the outbox row before the first drain,
        // re-insert it verbatim after, and drain again.
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        $record = $this->outbox->lanePrefix(self::LANE, 1)[0];
        $this->relay->drainOnce();
        $this->outbox->insert($record);
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
