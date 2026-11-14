<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Fixtures\RecordingEvents;
use Freyr\MessageBroker\Tests\Fixtures\RecordingLogger;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\CompetingAmqpRelay;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Log\LogLevel;

/**
 * Competing drain end to end (spec D-C1/D-C4/D-C5): N workers empty one lane
 * with no ordering promise; a publish failure backs off the whole claimed
 * batch without head-of-line blocking the rest of the lane. True concurrent
 * claim disjointness is proven at the store level (OutboxClaimTest) — here
 * two workers interleave drains sequentially, which exercises the same claim
 * path through real relays.
 */
final class CompetingAmqpRelayTest extends FunctionalTestCase
{
    private const string EXCHANGE = 'mb_competing_test';
    private const string QUEUE = 'mb_competing_test_q';
    private const string LANE = 'competing';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel; // topology + assertions only; relays own their channels
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
        $this->channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $this->channel->queue_declare(self::QUEUE, false, true, false, false);
        $this->channel->queue_bind(self::QUEUE, self::EXCHANGE, '#');
        $this->channel->queue_purge(self::QUEUE);

        $this->producer = new OutboxProducer(
            new PdoOutboxStore(self::$pdo, static::platform()),
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
        string $exchange = self::EXCHANGE,
        ?RecordingEvents $events = null,
        ?RecordingLogger $logger = null,
    ): CompetingAmqpRelay {
        return new CompetingAmqpRelay(
            outbox: new PdoOutboxStore(self::newConnection(), static::platform()),
            amqp: self::$amqp->channel(),
            publish: new AmqpPublishConfig(exchange: $exchange),
            contentType: JsonWireFormat::CONTENT_TYPE,
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
        while (($delivery = $this->channel->basic_get(self::QUEUE, no_ack: true)) !== null) {
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

        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));

        $delivered = $this->drainQueue();
        sort($delivered);
        sort($messages);
        self::assertSame($messages, $delivered, 'every message delivered exactly once; order not asserted (D-C5)');

        $relayed = array_filter($events->records, static fn (array $r): bool => $r['event'] === 'batch.relayed');
        self::assertNotEmpty($relayed, 'RELAYED fires with the existing taxonomy');
        $first = array_values($relayed)[0];
        self::assertSame(self::LANE, $first['context']['lane']);
        self::assertSame(2, $first['context']['count']);
    }

    public function testPublishFailureBacksOffTheClaimedBatchWithoutHeadOfLine(): void
    {
        foreach (range(1, 3) as $i) {
            $this->producer->produce(OrderPlaced::create("o-{$i}", $i * 100));
        }

        $logger = new RecordingLogger();
        $failing = $this->worker(batchSize: 100, exchange: 'missing_exchange_404', logger: $logger);
        self::assertSame(0, $failing->drainOnce());

        // The whole claimed batch backed off individually — attempts bumped,
        // nothing deleted, nothing eligible right now.
        self::assertSame(3, self::fetchInt('SELECT COUNT(*) FROM outbox_messages'));
        self::assertSame(3, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE attempts = 1'));
        $healthy = $this->worker(batchSize: 100);
        self::assertSame(0, $healthy->drainOnce(), 'backed-off rows are ineligible');

        $warnings = array_filter($logger->records, static fn (array $r): bool => $r['level'] === LogLevel::WARNING);
        self::assertNotEmpty($warnings, 'the failure is logged without an ErrorHandler');
        self::assertArrayHasKey('exception', array_values($warnings)[0]['context']);

        // No head-of-line: make ONE row eligible again — it drains past the
        // still-backed-off siblings (the ordered relay would keep the lane shut).
        self::$pdo->exec(
            "UPDATE outbox_messages SET available_at = '2000-01-01 00:00:00.000'"
            .' WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM outbox_messages) AS pick)',
        );
        self::assertSame(1, $healthy->drainOnce(), 'an eligible row overtakes backed-off rows');
        self::assertCount(1, $this->drainQueue());
    }
}
