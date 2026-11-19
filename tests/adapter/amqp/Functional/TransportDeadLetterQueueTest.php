<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Functional;

use Freyr\MessageBroker\Adapter\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Adapter\Amqp\AmqpDeadLetterImport;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Adapter\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\DeliveryLimitExceeded;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterBuilder;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterImporter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

/**
 * The import of a dead letter queue against RabbitMQ. The work queue is a
 * quorum queue with a dead letter exchange and its own x-delivery-limit; the
 * broker moves a message above that limit to the dead letter queue, and
 * AmqpDeadLetterImport moves it into the dead_letters table.
 */
final class TransportDeadLetterQueueTest extends AmqpFunctionalTestCase
{
    private const string QUEUE = 'mb_tdlq_q';

    private const string DEAD_LETTER_EXCHANGE = 'mb_tdlq_dlx';

    private const int DELAY_MS = 100;

    /** The broker moves a message after this many returns with no ack and one more delivery. */
    private const int BROKER_DELIVERY_LIMIT = 2;

    private static AMQPStreamConnection $amqp;

    private AMQPChannel $channel;

    private PostgreSqlDeadLetterStore $store;

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
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PostgreSqlDeadLetterStore(self::$pdo);
        $this->channel = self::$amqp->channel();
        $this->deleteTopology();
        $this->declareTopology();
    }

    protected function tearDown(): void
    {
        if (!$this->channel->is_open()) {
            $this->channel = self::$amqp->channel();
        }
        $this->deleteTopology();
        $this->channel->close();
    }

    private static function queue(): string
    {
        return self::isolated(self::QUEUE);
    }

    private static function deadLetterQueue(): string
    {
        return self::isolated(self::QUEUE.'.dlq');
    }

    private static function exchange(): string
    {
        return self::isolated(self::DEAD_LETTER_EXCHANGE);
    }

    private static function waitQueue(): string
    {
        return self::queue().'.wait.'.self::DELAY_MS;
    }

    private function deleteTopology(): void
    {
        $this->channel->queue_delete(self::queue());
        $this->channel->queue_delete(self::waitQueue());
        $this->channel->queue_delete(self::deadLetterQueue());
        $this->channel->exchange_delete(self::exchange());
    }

    private function declareTopology(bool $quorumDeadLetterQueue = false): void
    {
        $this->channel->exchange_declare(self::exchange(), 'direct', false, true, false);
        $this->channel->queue_declare(
            self::deadLetterQueue(),
            false,
            true,
            false,
            false,
            false,
            new AMQPTable($quorumDeadLetterQueue ? [
                'x-queue-type' => 'quorum',
            ] : []),
        );
        $this->channel->queue_bind(self::deadLetterQueue(), self::exchange(), self::deadLetterQueue());
        $this->channel->queue_declare(self::queue(), false, true, false, false, false, new AMQPTable([
            'x-queue-type' => 'quorum',
            'x-dead-letter-exchange' => self::exchange(),
            'x-dead-letter-routing-key' => self::deadLetterQueue(),
            'x-delivery-limit' => self::BROKER_DELIVERY_LIMIT,
        ]));
    }

    private function importer(?DeadLetterStore $store = null): DeadLetterImporter
    {
        return new DeadLetterImporter($store ?? $this->store, new JsonDeserializer(), new DeadLetterBuilder());
    }

    private function import(?DeadLetterStore $store = null, ?AMQPChannel $channel = null): AmqpDeadLetterImport
    {
        return new AmqpDeadLetterImport($channel ?? $this->channel, self::deadLetterQueue(), $this->importer($store));
    }

    /** @param array<string, mixed> $extraHeaders */
    private function publish(string $messageId, string $body = '{"order_id":"o-1"}', array $extraHeaders = []): void
    {
        $this->channel->basic_publish(
            new AMQPMessage($body, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(
                    MetadataHeader::envelope($messageId, 'order.placed', 1_765_476_000_123) + $extraHeaders,
                ),
            ]),
            '',
            self::queue(),
        );
    }

    /**
     * Receives one delivery of $queue on a fresh channel and closes the channel with no ack.
     *
     * @return bool false when no delivery arrives within 5 seconds
     */
    private function receiveAndCloseWithNoAck(string $queue): bool
    {
        $channel = self::$amqp->channel();
        $channel->basic_qos(0, 1, false);
        $received = 0;
        $channel->basic_consume(queue: $queue, callback: static function () use (&$received): void {
            ++$received;
        });
        try {
            while ($received === 0) {
                $channel->wait(timeout: 5);
            }
        } catch (AMQPTimeoutException) {
            // no delivery: the caller decides
        }
        $channel->close();

        return $received > 0;
    }

    /** Returns the message of the work queue with no ack until the broker moves it (its delivery limit). */
    private function moveByTheDeliveryLimit(): void
    {
        for ($return = 0; $return <= self::BROKER_DELIVERY_LIMIT; ++$return) {
            self::assertTrue($this->receiveAndCloseWithNoAck(self::queue()), "delivery {$return} of the work queue");
        }
        $this->awaitTheDeadLetterQueue();
    }

    /**
     * Waits until the broker has moved the message into the dead letter queue (the move is asynchronous). The wait
     * reads only the message count: a consume and a nack would add a delivery count on a quorum queue, and the
     * broker gives no event for the move.
     */
    private function awaitTheDeadLetterQueue(): void
    {
        $deadline = microtime(true) + 5;
        while ($this->messageCount(self::deadLetterQueue()) === 0) {
            self::assertLessThan($deadline, microtime(true), 'the broker did not move the message in 5 seconds');
            usleep(10_000);
        }
    }

    private function messageCount(string $queue): int
    {
        [, $ready] = $this->channel->queue_declare($queue, true);

        return (int) $ready;
    }

    /** @return list<DeadLetter> */
    private function rows(): array
    {
        return $this->store->list(new DeadLetterFilter());
    }

    /** A store that stores, then closes the channel and throws: the process stops before the ack. */
    private function storeThatStopsAfterTheStore(AMQPChannel $channel): DeadLetterStore
    {
        return $this->storeWith(function (DeadLetter $deadLetter) use ($channel): void {
            $this->store->store($deadLetter);
            $channel->close();

            throw new RuntimeException('the process stops before the ack');
        });
    }

    private function storeWith(\Closure $store): DeadLetterStore
    {
        return new class($this->store, $store) implements DeadLetterStore {
            public function __construct(
                private readonly PostgreSqlDeadLetterStore $inner,
                private readonly \Closure $store,
            ) {}

            public function store(DeadLetter $deadLetter): void
            {
                ($this->store)($deadLetter);
            }

            public function find(string $id): ?DeadLetter
            {
                return $this->inner->find($id);
            }

            public function list(DeadLetterFilter $filter, int $limit = 100, int $offset = 0): array
            {
                return $this->inner->list($filter, $limit, $offset);
            }

            public function count(DeadLetterFilter $filter): int
            {
                return $this->inner->count($filter);
            }

            public function ids(DeadLetterFilter $filter, int $limit): array
            {
                return $this->inner->ids($filter, $limit);
            }

            public function stats(DeadLetterFilter $filter): array
            {
                return $this->inner->stats($filter);
            }

            public function markReplayed(string $id): void
            {
                $this->inner->markReplayed($id);
            }

            public function purge(DeadLetterFilter $filter): int
            {
                return $this->inner->purge($filter);
            }
        };
    }

    /** Stores, then stops before the ack; returns the id of the stored row. */
    private function importAndStopBeforeTheAck(): string
    {
        $channel = self::$amqp->channel();
        try {
            $this->import($this->storeThatStopsAfterTheStore($channel), $channel)->run();
            self::fail('the stop must end run()');
        } catch (RuntimeException $e) {
            self::assertSame('the process stops before the ack', $e->getMessage());
        }
        self::assertCount(1, $this->rows());

        return $this->rows()[0]->id;
    }

    public function testAnExpiredWaitQueueMessageReturnsToTheWorkQueueAndNotToTheDeadLetterQueue(): void
    {
        $this->publish('m-1');
        $consumer = new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::queue()),
            processor: new DeliveryProcessor(
                deserializer: new JsonDeserializer(),
                dispatcher: new CallableDispatcher(static fn (IncomingMessage $incoming) => throw new RuntimeException('handler failed')),
                transaction: new PdoTransaction(self::$pdo),
                deduplication: new PostgreSqlDeduplicationStore(self::$pdo),
                retryPolicy: new ExponentialRetryPolicy(
                    maxAttempts: 3,
                    backoff: Backoff::exponential(initialDelayMs: self::DELAY_MS, maxDelayMs: self::DELAY_MS),
                ),
                deadLetters: $this->store,
                name: 'orders_consumer',
            ),
        );

        $consumer->run(messageLimit: 1, idleTimeoutSec: 2);

        $channel = self::$amqp->channel();
        $returned = null;
        $channel->basic_consume(
            queue: self::queue(),
            callback: static function (AMQPMessage $message) use (&$returned): void {
                $returned = $message;
                $message->ack();
            },
        );
        while ($returned === null) {
            $channel->wait(timeout: 5);
        }
        $channel->close();
        $headers = $returned->get('application_headers')->getNativeData();

        self::assertSame(2, $headers['x-attempt'], 'the retry came back to the work queue');
        self::assertSame(self::waitQueue(), $headers['x-death'][0]['queue']);
        self::assertSame('expired', $headers['x-death'][0]['reason']);
        self::assertSame(0, $this->messageCount(self::deadLetterQueue()), 'nothing reached the dead letter queue');
        self::assertSame([], $this->rows());
    }

    public function testAMessageAboveTheBrokerDeliveryLimitIsImportedAsAReplayableRowOfTheWorkQueue(): void
    {
        $this->publish('m-1', extraHeaders: [
            'x-attempt' => 3,
        ]);
        $this->moveByTheDeliveryLimit();

        self::assertSame(1, $this->import()->run());

        self::assertCount(1, $this->rows());
        $row = $this->rows()[0];
        self::assertSame(self::queue(), $row->source);
        self::assertSame('m-1', $row->messageId);
        self::assertSame(DeliveryLimitExceeded::class, $row->errorClass);
        self::assertSame(
            "Moved by the transport: reason 'delivery_limit', queue '".self::queue()."', count 1",
            $row->errorMessage,
            'the count of the x-death entry: one move from the work queue',
        );
        self::assertSame(
            '{"metadata":{"message_name":"order.placed","message_id":"m-1","created_at":1765476000123},"payload":{"order_id":"o-1"}}',
            $row->body,
        );
        self::assertTrue($row->replayable);
        self::assertSame(3, $row->attempts, 'the attempt of x-attempt');
        self::assertSame(0, $row->failedAt % 1000, 'the time of the move has second precision');
        self::assertEqualsWithDelta(time() * 1000, $row->failedAt, 60_000);
        self::assertArrayHasKey('x-death', $row->headers);
        self::assertSame(0, $this->messageCount(self::deadLetterQueue()), 'the import acked the message');
    }

    public function testAMessageThatPassedAWaitQueueAndThenTheLimitGivesTheWorkQueueAsSource(): void
    {
        $this->channel->queue_declare(self::waitQueue(), false, true, false, false, false, new AMQPTable([
            'x-message-ttl' => self::DELAY_MS,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => self::queue(),
        ]));
        $this->channel->basic_publish(
            new AMQPMessage('{"order_id":"o-1"}', [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(
                    MetadataHeader::envelope('m-1', 'order.placed', 1_765_476_000_123) + [
                        'x-attempt' => 2,
                    ],
                ),
            ]),
            '',
            self::waitQueue(),
        );
        $this->moveByTheDeliveryLimit();

        $this->import()->run();

        $row = $this->rows()[0];
        self::assertSame(self::queue(), $row->source, 'not the wait queue');
        self::assertSame(DeliveryLimitExceeded::class, $row->errorClass);
        self::assertSame(2, $row->attempts);
        self::assertTrue($row->replayable);
    }

    public function testAMalformedMovedMessageGivesARawRowThatIsNotReplayable(): void
    {
        $this->publish('m-bad', body: '{{{not json');
        $this->moveByTheDeliveryLimit();

        $this->import()->run();

        $row = $this->rows()[0];
        self::assertSame(self::queue(), $row->source);
        self::assertSame('m-bad', $row->messageId);
        self::assertSame('{{{not json', $row->body);
        self::assertSame(DeliveryLimitExceeded::class, $row->errorClass);
        self::assertStringContainsString('the body did not deserialize', $row->errorMessage);
        self::assertFalse($row->replayable);
    }

    public function testAStopBetweenTheStoreAndTheAckThenASecondRunGivesOneRow(): void
    {
        $this->publish('m-1');
        $this->moveByTheDeliveryLimit();
        $firstId = $this->importAndStopBeforeTheAck();

        self::assertSame(1, $this->import()->run(), 'the message returned at the close');

        self::assertCount(1, $this->rows());
        self::assertSame($firstId, $this->rows()[0]->id, 'the open row keeps its id');
        self::assertSame(0, $this->messageCount(self::deadLetterQueue()));
    }

    public function testAStoreThatFailsOnAQuorumDeadLetterQueueReturnsTheMessageWithNoNewCount(): void
    {
        $this->deleteTopology();
        $this->declareTopology(quorumDeadLetterQueue: true);
        $this->publish('m-1');
        $this->moveByTheDeliveryLimit();
        $error = new RuntimeException('database down');
        $countAtTheFailedImport = 'not read';

        try {
            $this->import($this->storeWith(static function (DeadLetter $deadLetter) use ($error, &$countAtTheFailedImport): void {
                $countAtTheFailedImport = $deadLetter->headers['x-delivery-count'] ?? null;

                throw $error;
            }))->run();
            self::fail('the store error must end run()');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e);
        }
        self::assertSame([], $this->rows());

        self::assertSame(1, $this->import()->run(), 'the next run imports it');

        $row = $this->rows()[0];
        self::assertSame(self::queue(), $row->source);
        self::assertSame(
            $countAtTheFailedImport,
            $row->headers['x-delivery-count'] ?? null,
            'the nack with requeue added no count of the dead letter queue',
        );
        self::assertSame(0, $this->messageCount(self::deadLetterQueue()));
    }

    public function testAReplayBeforeTheAckOfABrokerMovedMessageGivesASecondOpenRow(): void
    {
        $this->publish('m-1');
        $this->moveByTheDeliveryLimit();
        $firstId = $this->importAndStopBeforeTheAck();
        (new ReplayService($this->store, new InMemoryOutboxStore(), new JsonWireFormat()))->replay($firstId, 'orders');

        self::assertSame(1, $this->import()->run());

        $rows = $this->rows();
        self::assertCount(2, $rows, 'the limit of the spec: the next run gives a second row with a new id');
        $open = array_values(array_filter($rows, static fn (DeadLetter $row): bool => $row->replayedAt === null));
        self::assertCount(1, $open);
        self::assertNotSame($firstId, $open[0]->id);
        self::assertSame('m-1', $open[0]->messageId);
        self::assertSame(self::queue(), $open[0]->source);
    }
}
