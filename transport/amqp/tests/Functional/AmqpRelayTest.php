<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp\Tests\Functional;

use Freyr\MessageBroker\Outbox\MessageTooLarge;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Transport\Amqp\AmqpLaneLimits;
use Freyr\MessageBroker\Transport\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LogLevel;

/**
 * Relay end to end: N workers empty one lane with no ordering promise; a
 * broker failure backs off the failed rows and stops the worker (crash-only)
 * without head-of-line blocking the rest of the lane; a retried row goes
 * alone; a nack backs off only the nacked rows; an unroutable message is
 * dropped by design. True concurrent claim disjointness is proven
 * at the store level (OutboxClaimTest) — here two workers interleave drains
 * sequentially, which exercises the same claim path through real relays.
 */
final class AmqpRelayTest extends AmqpFunctionalTestCase
{
    private const string EXCHANGE = 'mb_competing_test';
    private const string QUEUE = 'mb_competing_test_q';
    private const string LANE = 'competing';
    private const string NACK_EXCHANGE = 'mb_relay_nack';
    private const string NACK_QUEUE = 'mb_relay_nack_q';
    private const string UNBOUND_EXCHANGE = 'mb_relay_unbound';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel; // topology + assertions only; relays own their channels
    private OutboxProducer $producer;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$amqp = self::connect();
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
        $this->channel->close();
    }

    /** A worker with its OWN PDO connection and OWN channel, as in production. */
    private function worker(
        int $batchSize = 2,
        ?string $exchange = null,
        ?RecordingEvents $events = null,
        ?RecordingLogger $logger = null,
        ?AMQPChannel $channel = null,
    ): Relay {
        return new Relay(
            outbox: new PostgreSqlOutboxStore(self::newConnection()),
            publisher: new AmqpMessagePublisher(
                $channel ?? self::$amqp->channel(),
                new AmqpPublishConfig(exchange: $exchange ?? self::isolated(self::EXCHANGE)),
                JsonWireFormat::CONTENT_TYPE,
            ),
            lane: self::LANE,
            batchSize: $batchSize,
            backoff: Backoff::exponential(initialDelayMs: 60_000, maxDelayMs: 600_000),
            logger: $logger ?? new RecordingLogger(),
            events: $events,
        );
    }

    /** @return list<string> message ids currently in the test queue */
    private function drainQueue(): array
    {
        $ids = [];
        while (($delivery = $this->channel->basic_get(self::isolated(self::QUEUE), no_ack: true)) !== null) {
            $ids[] = (string) $delivery->get_properties()['message_id'];
        }

        return $ids;
    }

    public function testTwoWorkersEmptyOneLaneAndEveryMessageIsDelivered(): void
    {
        $messages = [];
        foreach (range(1, 6) as $i) {
            $message = OrderPlaced::create("o-{$i}", $i * 100);
            $messages[] = $message->id;
            $this->producer->produce($message);
        }

        $events = new RecordingEvents();
        $workerA = $this->worker(batchSize: 2, events: $events);
        $workerB = $this->worker(batchSize: 2);

        // Interleave passes until both report an empty lane.
        do {
            $drained = $workerA->drainOnce() + $workerB->drainOnce();
        } while ($drained > 0);

        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'));

        $delivered = $this->drainQueue();
        sort($delivered);
        sort($messages);
        self::assertSame($messages, $delivered, 'every message delivered exactly once; order not asserted');

        $relayed = array_filter($events->records, static fn (array $r): bool => $r['event'] === 'batch.relayed');
        self::assertNotEmpty($relayed, 'RELAYED fires with the existing taxonomy');
        $first = array_values($relayed)[0];
        self::assertSame(self::LANE, $first['context']['lane']);
        self::assertSame(2, $first['context']['count']);
    }

    public function testBrokerFailureBacksOffTheBatchAndStopsTheWorker(): void
    {
        foreach (range(1, 3) as $i) {
            $this->producer->produce(OrderPlaced::create("o-{$i}", $i * 100));
        }

        $logger = new RecordingLogger();
        $failing = $this->worker(batchSize: 100, exchange: 'missing_exchange_404', logger: $logger);
        try {
            $failing->drainOnce();
            self::fail('a channel failure must stop the worker (crash-only)');
        } catch (AMQPProtocolChannelException $e) {
            self::assertSame(404, $e->getCode(), 'NOT_FOUND: a missing exchange closes the channel only');
        }

        // The connection stays open: a new channel on it publishes and gets its confirm.
        self::assertTrue(self::$amqp->isConnected());
        $probe = self::$amqp->channel();
        $probe->confirm_select();
        $probe->exchange_declare(self::isolated('mb_relay_probe'), 'topic', false, false, true);
        $probe->basic_publish(new AMQPMessage('probe'), self::isolated('mb_relay_probe'), 'probe');
        $probe->wait_for_pending_acks();
        $probe->close();

        // The whole claimed batch backed off individually (committed before
        // the error propagated) — attempts bumped, nothing marked published.
        self::assertSame(3, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'));
        self::assertSame(3, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE attempts = 1'));
        $healthy = $this->worker(batchSize: 100);
        self::assertSame(0, $healthy->drainOnce(), 'backed-off rows are ineligible');

        $warnings = array_filter($logger->records, static fn (array $r): bool => $r['level'] === LogLevel::WARNING);
        self::assertNotEmpty($warnings, 'the failure is logged without an ErrorHandler');
        self::assertArrayHasKey('exception', array_values($warnings)[0]['context']);

        // No head-of-line: make ONE row eligible again — it drains past the
        // still-backed-off siblings.
        self::$pdo->exec(
            "UPDATE outbox_messages SET available_at = '2000-01-01 00:00:00.000'"
            .' WHERE id = (SELECT id FROM outbox_messages ORDER BY id DESC LIMIT 1)',
        );
        self::assertSame(1, $healthy->drainOnce(), 'an eligible row overtakes backed-off rows');
        self::assertCount(1, $this->drainQueue());
    }

    public function testARetriedRowIsPublishedAloneSoARefusedMessageFailsAlone(): void
    {
        $ids = [];
        foreach (range(1, 3) as $i) {
            $message = OrderPlaced::create("o-{$i}", $i * 100);
            $ids[] = $message->id;
            $this->producer->produce($message);
        }
        [$before, $refused, $after] = $ids;
        // All three failed once; the middle one is larger than the broker accepts.
        foreach ($ids as $position => $id) {
            self::$pdo->exec(
                "UPDATE outbox_messages SET attempts = 1, available_at = '2000-01-01 00:00:0{$position}.000' WHERE id = '{$id}'",
            );
        }
        self::$pdo->exec(
            "UPDATE outbox_messages SET body = convert_to(repeat('x', 17 * 1024 * 1024), 'UTF8') WHERE id = '{$refused}'",
        );

        $connection = self::connect(); // the refusal may close the connection: keep it away from the shared one
        $relay = $this->worker(batchSize: 100, channel: $connection->channel());
        try {
            $relay->drainOnce();
            self::fail('the refused message must stop the worker');
        } catch (\Throwable) {
        }

        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM outbox_messages WHERE id = '{$before}' AND published_at IS NOT NULL"));
        self::assertSame(2, self::fetchInt("SELECT attempts FROM outbox_messages WHERE id = '{$refused}'"), 'only the refused row backs off');
        self::assertSame(
            1,
            self::fetchInt("SELECT attempts FROM outbox_messages WHERE id = '{$after}' AND published_at IS NULL"),
            'the row after it was not attempted: unchanged and still due',
        );
        self::assertSame(1, $this->worker(batchSize: 100)->drainOnce(), 'the next worker publishes the row after it');
        try {
            $connection->close();
        } catch (\Throwable) {
        }
    }

    public function testHeadersAtTheRabbitMqLimitPassTheProducerAndAreConfirmedAndOneByteMoreIsRefused(): void
    {
        $limits = AmqpLaneLimits::rabbitMqDefaults();
        $limit = $limits->maxHeaderBytes;
        self::assertNotNull($limit);
        $producer = new OutboxProducer(
            new PostgreSqlOutboxStore(self::$pdo),
            new JsonWireFormat(),
            lane: self::LANE,
            limits: $limits,
        );

        // The worst case of the AMQP table form: one list of small integers.
        $atLimit = self::integerListHeaders($limit, 10);
        self::assertSame($limit, strlen(json_encode($atLimit, JSON_THROW_ON_ERROR)));
        $message = OrderPlaced::create('o-1', 100);
        $producer->produce($message, $atLimit);

        self::assertSame(1, $this->worker(batchSize: 100)->drainOnce(), 'the broker confirmed the message with headers at the limit');
        self::assertSame([$message->id], $this->drainQueue());
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'));

        $above = self::integerListHeaders($limit + 1, 100);
        self::assertSame($limit + 1, strlen(json_encode($above, JSON_THROW_ON_ERROR)));
        try {
            $producer->produce(OrderPlaced::create('o-2', 200), $above);
            self::fail('expected MessageTooLarge');
        } catch (MessageTooLarge $e) {
            self::assertSame('headers', $e->part);
            self::assertSame($limit + 1, $e->size);
            self::assertSame($limit, $e->limit);
        }
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'));
    }

    /**
     * Headers {"ids":[0,0,...,$last]} whose JSON form is exactly $jsonBytes long.
     *
     * @return array{ids: list<int>}
     */
    private static function integerListHeaders(int $jsonBytes, int $last): array
    {
        // {"ids":[ and ]} are 10 bytes; each zero takes 2 bytes with its comma; the last value takes strlen($last) bytes.
        $zeroBytes = $jsonBytes - 10 - strlen((string) $last);
        self::assertSame(0, $zeroBytes % 2, 'the target size must be reachable with this last value');
        $ids = array_fill(0, intdiv($zeroBytes, 2), 0);
        $ids[] = $last;

        return [
            'ids' => $ids,
        ];
    }

    public function testNackedMessagesBackOffAndTheWorkerKeepsRunning(): void
    {
        // A queue that rejects every publish: the broker nacks each message.
        $this->channel->exchange_declare(self::isolated(self::NACK_EXCHANGE), 'topic', false, false, false);
        $this->channel->queue_declare(self::isolated(self::NACK_QUEUE), false, true, false, false, false, new AMQPTable([
            'x-max-length' => 0,
            'x-overflow' => 'reject-publish',
        ]));
        $this->channel->queue_bind(self::isolated(self::NACK_QUEUE), self::isolated(self::NACK_EXCHANGE), '#');
        $this->producer->produce(OrderPlaced::create('o-1', 100));
        $this->producer->produce(OrderPlaced::create('o-2', 200));

        $logger = new RecordingLogger();
        $relay = $this->worker(batchSize: 100, exchange: self::isolated(self::NACK_EXCHANGE), logger: $logger);

        self::assertSame(0, $relay->drainOnce(), 'a nacked message is not marked published');
        self::assertSame(2, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE attempts = 1 AND published_at IS NULL'));
        self::assertNotEmpty(array_filter(
            $logger->records,
            static fn (array $r): bool => str_contains((string) $r['message'], 'nacked'),
        ));

        // The channel stays usable: the same worker publishes a new row elsewhere routed.
        $this->channel->queue_delete(self::isolated(self::NACK_QUEUE));
        $this->producer->produce(OrderPlaced::create('o-3', 300));
        self::assertSame(1, $relay->drainOnce(), 'unroutable now, so dropped by the broker and confirmed');
    }

    public function testAnUnroutableMessageIsDroppedByDesignAndMarkedPublished(): void
    {
        $this->channel->exchange_declare(self::isolated(self::UNBOUND_EXCHANGE), 'topic', false, false, true);
        $this->producer->produce(OrderPlaced::create('o-1', 100));

        self::assertSame(1, $this->worker(exchange: self::isolated(self::UNBOUND_EXCHANGE))->drainOnce());
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'));
        $this->channel->exchange_delete(self::isolated(self::UNBOUND_EXCHANGE));
    }

    private static function connect(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            host: getenv('AMQP_HOST') ?: 'rabbitmq',
            port: (int) (getenv('AMQP_PORT') ?: 5672),
            user: getenv('AMQP_USER') ?: 'guest',
            password: getenv('AMQP_PASSWORD') ?: 'guest',
            vhost: getenv('AMQP_VHOST') ?: '/',
        );
    }
}
