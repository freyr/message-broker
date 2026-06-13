<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Consumer\Binding;
use Freyr\MessageBroker\Consumer\HandlerRegistry;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Consumer\PdoDeduplicationStore;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlacedDto;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RuntimeException;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The slice 1 promise, verified end to end against real MySQL and RabbitMQ:
 *
 *   produce → outbox → relay (ordered, confirmed) → exchange → queue →
 *   consumer (dedup, typed dispatch) → handler
 *
 * and the failure circle:
 *
 *   failing handler → TTL-wait-queue retries → exhaustion → database DLQ →
 *   replay (back through the outbox + relay) → fixed handler succeeds
 */
final class EndToEndTest extends FunctionalTestCase
{
    private const string EXCHANGE = 'mb_e2e';
    private const string QUEUE = 'mb_e2e_q';
    private const string LANE = 'e2e';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel;        // consumer + topology
    private AMQPChannel $relayChannel;   // confirms are channel-global: the relay owns its channel
    private MySqlPlatform $platform;
    private OutboxStore $outbox;
    private OutboxProducer $producer;
    private PdoDeadLetterStore $deadLetters;
    private AmqpRelay $relay; // one long-lived relay per channel, as in production

    private bool $handlerFails = false;

    private int $handlerAttempts = 0;

    /** @var list<array{message: OrderPlacedDto, envelope: IncomingMessage}> */
    private array $handled = [];

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
        $channel->queue_delete(self::QUEUE.'.wait.100');
        $channel->exchange_delete(self::EXCHANGE);
        $channel->close();
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->handled = [];
        $this->handlerFails = false;
        $this->handlerAttempts = 0;

        $this->channel = self::$amqp->channel();
        $this->relayChannel = self::$amqp->channel();
        $this->channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $this->channel->queue_declare(self::QUEUE, false, true, false, false);
        $this->channel->queue_bind(self::QUEUE, self::EXCHANGE, 'order.*');
        $this->channel->queue_purge(self::QUEUE);

        $this->platform = new MySqlPlatform();
        $this->outbox = new OutboxStore(self::$pdo, $this->platform);
        $this->producer = new OutboxProducer($this->outbox, new JsonWireFormat(), lane: self::LANE);
        $this->deadLetters = new PdoDeadLetterStore(self::$pdo, $this->platform);
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
    }

    private function consumer(): AmqpConsumer
    {
        $handler = function (OrderPlacedDto $message, IncomingMessage $envelope): void {
            ++$this->handlerAttempts;
            if ($this->handlerFails) {
                throw new RuntimeException('temporary downstream outage');
            }
            $this->handled[] = [
                'message' => $message,
                'envelope' => $envelope,
            ];
        };

        return new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::QUEUE),
            deserializer: new JsonDeserializer(),
            handlers: new HandlerRegistry(
                bindings: [
                    'order.placed' => new Binding(OrderPlacedDto::class, $handler),
                ],
                denormalizer: new Serializer([
                    new ObjectNormalizer(nameConverter: new CamelCaseToSnakeCaseNameConverter()),
                ]),
            ),
            pdo: self::$pdo,
            deduplication: new PdoDeduplicationStore(self::$pdo, $this->platform),
            retryPolicy: new AmqpRetryPolicy(
                maxAttempts: 2,
                backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
            ),
            deadLetters: $this->deadLetters,
            name: 'e2e_consumer',
        );
    }

    public function testHappyPathFromProduceToTypedHandler(): void
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

        self::assertCount(1, $this->handled);
        self::assertSame('o-42', $this->handled[0]['message']->orderId);
        self::assertSame(12_500, $this->handled[0]['message']->totalCents);
        self::assertSame($message->id, $this->handled[0]['envelope']->messageId);
        self::assertSame($message->createdAt, $this->handled[0]['envelope']->createdAt);
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'), 'outbox drained');
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
        self::assertCount(0, $this->handled);
        $deadLetters = $this->deadLetters->list();
        self::assertCount(1, $deadLetters);
        self::assertSame($message->id, $deadLetters[0]->messageId);

        // Downstream recovered: replay rides the outbox + relay again.
        $this->handlerFails = false;
        $replay = new ReplayService($this->deadLetters, $this->outbox, new JsonWireFormat());
        $replay->replay($deadLetters[0]->id, lane: self::LANE);

        self::assertSame(1, $this->relay->drainOnce());
        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->handled, 'replayed message must reach the fixed handler');
        self::assertSame($message->id, $this->handled[0]['envelope']->messageId);
        $replayed = $this->deadLetters->find($deadLetters[0]->id);
        self::assertNotNull($replayed);
        self::assertNotNull($replayed->replayedAt, 'dead letter kept for audit, marked replayed');
    }
}
