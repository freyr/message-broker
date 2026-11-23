<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp\Tests\Functional;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\DeliveryLimitExceeded;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Storage\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Worker\WorkerLimits;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

/**
 * The delivery limit and the return rule against RabbitMQ, on a quorum work
 * queue (the queue type that gives x-delivery-count).
 */
final class DeliveryLimitTest extends AmqpFunctionalTestCase
{
    private const string QUEUE = 'mb_delivery_limit_q';

    private const int DELAY_MS = 100;

    private static AMQPStreamConnection $amqp;

    private AMQPChannel $channel;

    /** @var list<string> the message ids that reached the handler */
    private array $handled = [];

    private ?\Closure $failingDispatch = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$amqp = self::connect();
    }

    public static function tearDownAfterClass(): void
    {
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->handled = [];
        $this->failingDispatch = null;
        $this->channel = self::$amqp->channel();
        $this->channel->queue_delete(self::queue());
        $this->channel->queue_delete(self::queue().'.wait.'.self::DELAY_MS);
        $this->channel->queue_declare(self::queue(), false, true, false, false, false, new AMQPTable([
            'x-queue-type' => 'quorum',
        ]));
    }

    protected function tearDown(): void
    {
        if ($this->channel->is_open()) {
            $this->channel->queue_delete(self::queue());
            $this->channel->queue_delete(self::queue().'.wait.'.self::DELAY_MS);
            $this->channel->close();
        }
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

    private static function queue(): string
    {
        return self::isolated(self::QUEUE);
    }

    private function consumer(?Deserializer $deserializer = null, ?AMQPChannel $channel = null): AmqpConsumer
    {
        return new AmqpConsumer(
            channel: $channel ?? $this->channel,
            queue: new AmqpQueueConfig(self::queue()),
            processor: new DeliveryProcessor(
                deserializer: $deserializer ?? new JsonDeserializer(),
                dispatcher: new CallableDispatcher(function (IncomingMessage $incoming): void {
                    if ($this->failingDispatch !== null) {
                        ($this->failingDispatch)($incoming);
                    }
                    $this->handled[] = $incoming->messageId;
                }),
                transaction: new PdoTransaction(self::$pdo),
                deduplication: new PostgreSqlDeduplicationStore(self::$pdo),
                retryPolicy: new ExponentialRetryPolicy(
                    maxAttempts: 3,
                    backoff: Backoff::exponential(initialDelayMs: self::DELAY_MS, maxDelayMs: self::DELAY_MS),
                ),
                deadLetters: new PostgreSqlDeadLetterStore(self::$pdo),
                name: 'orders_consumer',
            ),
        );
    }

    /** A deserializer that throws a transient error for the first $failures deliveries of one message. */
    private static function failingFor(string $messageId, int $failures): Deserializer
    {
        return new class($messageId, $failures) implements Deserializer {
            public function __construct(
                private readonly string $messageId,
                private int $failures,
            ) {}

            public function deserialize(string $bytes, array $headers = []): IncomingMessage
            {
                if (($headers['x-message-id'] ?? null) === $this->messageId && $this->failures > 0) {
                    --$this->failures;

                    throw new RuntimeException('database down');
                }

                return (new JsonDeserializer())->deserialize($bytes, $headers);
            }
        };
    }

    private function publish(string ...$messageIds): void
    {
        foreach ($messageIds as $messageId) {
            $this->channel->basic_publish(
                new AMQPMessage((string) json_encode([
                    'order_id' => $messageId,
                ]), [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'application_headers' => new AMQPTable(
                        MetadataHeader::envelope($messageId, 'order.placed', 1_765_476_000_123),
                    ),
                ]),
                '',
                self::queue(),
            );
        }
    }

    /**
     * Receives up to $expected deliveries on a fresh channel and acks them.
     *
     * @return array<string, int|null> message id => x-delivery-count (null when absent)
     */
    private function receiveAll(int $expected): array
    {
        $channel = self::$amqp->channel();
        $channel->basic_qos(0, $expected, false);
        $counts = [];
        $channel->basic_consume(
            queue: self::queue(),
            callback: static function (AMQPMessage $message) use (&$counts): void {
                $headers = $message->get('application_headers')->getNativeData();
                $count = $headers['x-delivery-count'] ?? null;
                $counts[(string) $headers['x-message-id']] = is_int($count) ? $count : null;
                $message->ack();
            },
        );
        try {
            while (count($counts) < $expected) {
                $channel->wait(timeout: 5);
            }
        } catch (AMQPTimeoutException) {
            // fewer deliveries than expected: the assertion of the caller shows it
        }
        $channel->close();
        ksort($counts);

        return $counts;
    }

    /** Receives one delivery on a fresh channel and closes the channel with no ack. */
    private function receiveAndCloseWithNoAck(): void
    {
        $channel = self::$amqp->channel();
        $channel->basic_qos(0, 1, false);
        $received = 0;
        $channel->basic_consume(queue: self::queue(), callback: static function () use (&$received): void {
            ++$received;
        });
        while ($received === 0) {
            $channel->wait(timeout: 5);
        }
        $channel->close();
    }

    private function deadLetterRows(): array
    {
        $statement = self::$pdo->query('SELECT message_id, source, error_class, attempts FROM dead_letters ORDER BY message_id');
        self::assertNotFalse($statement);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testADeliveryThatEndedWithNoAckFiveTimesBecomesADeadLetter(): void
    {
        $this->publish('m-1');
        for ($close = 1; $close <= 5; ++$close) {
            $this->receiveAndCloseWithNoAck();
        }

        $this->consumer()->run(idleTimeoutSec: 2);

        self::assertSame([], $this->handled, 'the handler does not run');
        self::assertSame([[
            'message_id' => 'm-1',
            'source' => self::queue(),
            'error_class' => DeliveryLimitExceeded::class,
            'attempts' => 1,
        ]], $this->deadLetterRows());
        [, $ready, $consumers] = $this->channel->queue_declare(self::queue(), true);
        self::assertSame(0, $ready, 'the work queue is empty');
        self::assertSame(0, $consumers);
    }

    public function testTheFirstDeliveryAfterAWaitQueuePassHasNoCount(): void
    {
        $this->publish('m-1');
        $this->receiveAndCloseWithNoAck();
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');

        $this->consumer()->run(new WorkerLimits(maxMessages: 1), idleTimeoutSec: 2);

        $this->failingDispatch = null;
        $channel = self::$amqp->channel();
        $consumer = $this->consumer(channel: $channel);
        $deliveries = [];
        $channel->basic_qos(0, 1, false);
        $channel->basic_consume(
            queue: self::queue(),
            callback: static function (AMQPMessage $message) use (&$deliveries, $consumer): void {
                $deliveries[] = $consumer->deliveryFrom($message);
                $message->ack();
            },
        );
        while ($deliveries === []) {
            $channel->wait(timeout: 5);
        }
        $channel->close();

        self::assertSame(2, $deliveries[0]->attempt, 'the delivery came back through the wait queue');
        self::assertNull($deliveries[0]->deliveryCount, 'the wait-queue pass starts the count again');
    }

    /** The graceful stop of this test is a limit stop (maxMessages: 1): it adds no count either. */
    public function testAGracefulStopReturnsTheBufferedDeliveriesWithNoCount(): void
    {
        $this->publish('m-1', 'm-2', 'm-3', 'm-4', 'm-5');

        $this->consumer()->run(new WorkerLimits(maxMessages: 1), idleTimeoutSec: 10);

        self::assertSame(['m-1'], $this->handled);
        self::assertSame([
            'm-2' => null,
            'm-3' => null,
            'm-4' => null,
            'm-5' => null,
        ], $this->receiveAll(4));
    }

    public function testAThrowableFromTheProcessorCountsOnlyForTheDeliveryInWork(): void
    {
        $this->publish('m-1', 'm-2', 'm-3', 'm-4', 'm-5');

        try {
            $this->consumer(self::failingFor('m-1', 1))->run();
            self::fail('the failure must end run()');
        } catch (RuntimeException $e) {
            self::assertSame('database down', $e->getMessage());
        }

        self::assertTrue($this->channel->is_open(), 'the return keeps the channel open');
        self::assertSame([], $this->handled);
        self::assertSame([
            'm-1' => 1,
            'm-2' => null,
            'm-3' => null,
            'm-4' => null,
            'm-5' => null,
        ], $this->receiveAll(5));
    }

    public function testRepeatedFailuresMakeOnlyTheDeliveryInWorkADeadLetter(): void
    {
        $this->publish('m-1', 'm-2', 'm-3', 'm-4', 'm-5');
        $deserializer = self::failingFor('m-1', 5);

        for ($stop = 1; $stop <= 5; ++$stop) {
            try {
                $this->consumer($deserializer)->run(idleTimeoutSec: 2);
                self::fail("stop {$stop}: the failure must end run()");
            } catch (RuntimeException $e) {
                self::assertSame('database down', $e->getMessage());
            }
        }
        $this->consumer($deserializer)->run(idleTimeoutSec: 2);

        self::assertSame([[
            'message_id' => 'm-1',
            'source' => self::queue(),
            'error_class' => DeliveryLimitExceeded::class,
            'attempts' => 1,
        ]], $this->deadLetterRows(), 'only the delivery in work reaches the limit');
        $handled = $this->handled;
        sort($handled);
        self::assertSame(['m-2', 'm-3', 'm-4', 'm-5'], $handled);
    }

    public function testAConnectionCloseWithNoReturnCountsForEachBufferedDelivery(): void
    {
        $this->publish('m-1', 'm-2', 'm-3', 'm-4', 'm-5');
        $connection = self::connect();
        $channel = $connection->channel();
        $channel->basic_qos(0, 10, false);
        $received = 0;
        $channel->basic_consume(queue: self::queue(), callback: static function () use (&$received): void {
            ++$received;
        });
        while ($received < 5) {
            $channel->wait(timeout: 5);
        }

        $connection->close();

        self::assertSame([
            'm-1' => 1,
            'm-2' => 1,
            'm-3' => 1,
            'm-4' => 1,
            'm-5' => 1,
        ], $this->receiveAll(5));
    }

    public function testAnIdleStopOnAnEmptyQueueEndsWithNoErrorAndKeepsTheChannelOpen(): void
    {
        $this->consumer()->run(idleTimeoutSec: 1);

        // A round trip on the channel: a channel error of the nack would close it.
        [, $ready] = $this->channel->queue_declare(self::queue(), true);
        self::assertSame(0, $ready);
        self::assertTrue($this->channel->is_open());
    }
}
