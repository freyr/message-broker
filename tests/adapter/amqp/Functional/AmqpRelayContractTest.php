<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Functional;

use Freyr\MessageBroker\Adapter\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Adapter\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Fixtures\RecordingErrorHandler;
use Freyr\MessageBroker\Tests\Fixtures\RecordingEvents;
use Freyr\MessageBroker\Tests\Fixtures\RecordingLogger;
use Freyr\MessageBroker\Time\EpochMillis;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * What the relay publishes (properties, headers, routing key), how it backs
 * off failed rows (default and injected backoff), what it logs and reports
 * on a failure or a nack, and its defaults.
 */
final class AmqpRelayContractTest extends AmqpFunctionalTestCase
{
    private const string EXCHANGE = 'mb_relay_contract';
    private const string QUEUE = 'mb_relay_contract_q';
    private const string NACK_EXCHANGE = 'mb_relay_contract_nack';
    private const string NACK_QUEUE = 'mb_relay_contract_nack_q';
    private const string LANE = 'contract';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel;
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
        $channel->queue_delete(self::isolated(self::QUEUE));
        $channel->queue_delete(self::isolated(self::NACK_QUEUE));
        $channel->exchange_delete(self::isolated(self::EXCHANGE));
        $channel->exchange_delete(self::isolated(self::NACK_EXCHANGE));
        $channel->close();
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = self::$amqp->channel();
        $this->channel->exchange_declare(self::isolated(self::EXCHANGE), 'topic', false, true, false);
        $this->channel->queue_declare(self::isolated(self::QUEUE), false, true, false, false);
        $this->channel->queue_bind(self::isolated(self::QUEUE), self::isolated(self::EXCHANGE), '#');
        $this->channel->queue_purge(self::isolated(self::QUEUE));

        $this->producer = new OutboxProducer(
            new PostgreSqlOutboxStore(self::$pdo),
            new JsonWireFormat(),
            lane: self::LANE,
        );
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        $this->channel->close();
    }

    private function relay(
        ?string $exchange = null,
        ?Backoff $backoff = null,
        ?RecordingLogger $logger = null,
        ?RecordingErrorHandler $errorHandler = null,
        ?RecordingEvents $events = null,
        ?int $batchSize = null,
    ): Relay {
        $arguments = [
            'outbox' => new PostgreSqlOutboxStore(self::newConnection()),
            'publisher' => new AmqpMessagePublisher(
                self::$amqp->channel(),
                new AmqpPublishConfig(exchange: $exchange ?? self::isolated(self::EXCHANGE)),
                JsonWireFormat::CONTENT_TYPE,
            ),
            'lane' => self::LANE,
            'backoff' => $backoff,
            'errorHandler' => $errorHandler,
            'logger' => $logger ?? new RecordingLogger(),
            'events' => $events,
        ];
        if ($batchSize !== null) {
            $arguments['batchSize'] = $batchSize;
        }

        return new Relay(...$arguments);
    }

    private static function availableAtMs(string $id): int
    {
        return self::fetchInt(
            "SELECT (EXTRACT(EPOCH FROM available_at) * 1000)::bigint FROM outbox_messages WHERE id = '{$id}'",
        );
    }

    private function declareRejectingQueue(): void
    {
        $this->channel->exchange_declare(self::isolated(self::NACK_EXCHANGE), 'topic', false, false, false);
        $this->channel->queue_declare(self::isolated(self::NACK_QUEUE), false, true, false, false, false, new AMQPTable([
            'x-max-length' => 0,
            'x-overflow' => 'reject-publish',
        ]));
        $this->channel->queue_bind(self::isolated(self::NACK_QUEUE), self::isolated(self::NACK_EXCHANGE), '#');
    }

    public function testPublishedMessageCarriesPropertiesRoutingKeyAndHeaders(): void
    {
        $message = OrderPlaced::create('o-1', 100);
        $this->producer->produce($message, [
            'x-trace' => 'abc',
            'x-message-id' => 'forged', // the envelope wins on a collision
        ]);

        self::assertSame(1, $this->relay()->drainOnce());

        $delivery = $this->channel->basic_get(self::isolated(self::QUEUE), no_ack: true);
        self::assertNotNull($delivery);
        $properties = $delivery->get_properties();
        self::assertSame(JsonWireFormat::CONTENT_TYPE, $properties['content_type']);
        self::assertSame($message->id, $properties['message_id']);
        self::assertSame(2, $properties['delivery_mode'], 'persistent');
        self::assertSame('order.placed', $delivery->getRoutingKey());
        self::assertEquals(
            [
                'x-trace' => 'abc',
                'x-message-id' => $message->id,
                'x-message-name' => 'order.placed',
                'x-created-at' => $message->createdAt,
            ],
            $properties['application_headers']->getNativeData(),
        );
        self::assertSame([
            'order_id' => 'o-1',
            'total_cents' => 100,
        ], json_decode($delivery->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAnEmptyLaneRecordsNoRelayedEvent(): void
    {
        $events = new RecordingEvents();

        self::assertSame(0, $this->relay(events: $events)->drainOnce());

        self::assertSame([], $events->names());
    }

    public function testDefaultBatchSizeIsFiveHundred(): void
    {
        foreach (range(1, 501) as $i) {
            $this->producer->produce(OrderPlaced::create("o-{$i}", $i));
        }

        self::assertSame(500, $this->relay()->drainOnce());
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'));
    }

    /** @return iterable<string, array{?int, ?int, int, int}> initial delay, max delay, attempts so far, expected back-off */
    public static function backoffCases(): iterable
    {
        yield 'injected backoff, first failure' => [7_000, 10_000_000, 0, 7_000];
        yield 'injected backoff, second failure' => [7_000, 10_000_000, 1, 28_000];
        yield 'default backoff, first failure' => [null, null, 0, 1_000];
        yield 'default backoff, second failure' => [null, null, 1, 4_000];
        yield 'default backoff is capped at five minutes' => [null, null, 10, 300_000];
    }

    #[DataProvider('backoffCases')]
    public function testAFailedRowBacksOffByTheBackoffOfItsNextAttempt(?int $initialMs, ?int $maxMs, int $attempts, int $expectedDelayMs): void
    {
        $clock = new MockClock();
        Clock::set($clock);
        $message = OrderPlaced::create('o-1', 100);
        $this->producer->produce($message);
        self::$pdo->exec("UPDATE outbox_messages SET attempts = {$attempts} WHERE id = '{$message->id}'");

        $relay = $this->relay(
            exchange: 'missing_exchange_404',
            backoff: $initialMs === null ? null : Backoff::exponential(initialDelayMs: $initialMs, maxDelayMs: $maxMs ?? 0),
        );
        try {
            $relay->drainOnce();
            self::fail('a channel failure must stop the worker');
        } catch (AMQPProtocolChannelException) {
        }

        self::assertSame(EpochMillis::now() + $expectedDelayMs, self::availableAtMs($message->id));
        self::assertSame($attempts + 1, self::fetchInt("SELECT attempts FROM outbox_messages WHERE id = '{$message->id}'"));
    }

    public function testANackIsLoggedWithLaneRowCountAndIdsAndDoesNotReachTheErrorHandler(): void
    {
        $this->declareRejectingQueue();
        $first = OrderPlaced::create('o-1', 100);
        $second = OrderPlaced::create('o-2', 200);
        $this->producer->produce($first);
        $this->producer->produce($second);
        $logger = new RecordingLogger();
        $errorHandler = new RecordingErrorHandler();

        $relay = $this->relay(exchange: self::isolated(self::NACK_EXCHANGE), logger: $logger, errorHandler: $errorHandler);

        self::assertSame(0, $relay->drainOnce());
        self::assertCount(1, $logger->records);
        $record = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $record['level']);
        self::assertSame('Broker nacked messages; they back off', $record['message']);
        self::assertArrayNotHasKey('exception', $record['context']);
        self::assertSame(self::LANE, $record['context']['lane']);
        self::assertSame(2, $record['context']['rows']);
        $ids = $record['context']['ids'];
        sort($ids);
        $expected = [$first->id, $second->id];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame([], $errorHandler->calls, 'a nack is not an error: the worker keeps running');
    }

    public function testAFailureLogsAndReportsLaneRowCountAndTheFirstTwentyIds(): void
    {
        $ids = [];
        foreach (range(1, 25) as $i) {
            $message = OrderPlaced::create("o-{$i}", $i);
            $ids[] = $message->id;
            $this->producer->produce($message);
        }
        // The claim reads oldest-due first: make the order explicit.
        foreach ($ids as $position => $id) {
            $seconds = sprintf('%02d', $position);
            self::$pdo->exec("UPDATE outbox_messages SET available_at = '2000-01-01 00:00:{$seconds}.000' WHERE id = '{$id}'");
        }
        $logger = new RecordingLogger();
        $errorHandler = new RecordingErrorHandler();

        $relay = $this->relay(exchange: 'missing_exchange_404', logger: $logger, errorHandler: $errorHandler, batchSize: 100);
        try {
            $relay->drainOnce();
            self::fail('a channel failure must stop the worker');
        } catch (AMQPProtocolChannelException) {
        }

        $expectedContext = [
            'lane' => self::LANE,
            'rows' => 25,
            'ids' => array_slice($ids, 0, 20),
        ];
        self::assertCount(1, $logger->records);
        $record = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $record['level']);
        self::assertSame('Relay publish failed; the failed rows back off and the worker stops', $record['message']);
        self::assertInstanceOf(AMQPProtocolChannelException::class, $record['context']['exception']);
        unset($record['context']['exception']);
        self::assertSame($expectedContext, $record['context']);

        self::assertCount(1, $errorHandler->calls);
        self::assertInstanceOf(AMQPProtocolChannelException::class, $errorHandler->calls[0]['error']);
        self::assertSame($expectedContext, $errorHandler->calls[0]['context']);
    }
}
