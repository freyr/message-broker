<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Functional;

use Freyr\MessageBroker\Adapter\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Adapter\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Time\EpochMillis;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LogLevel;
use RuntimeException;

final class AmqpConsumerTest extends AmqpFunctionalTestCase
{
    private const string QUEUE = 'mb_consumer_test_q';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel;

    /** @var list<IncomingMessage> */
    private array $dispatched = [];

    private ?\Closure $failingDispatch = null;

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
        $channel->close();
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatched = [];
        $this->channel = self::$amqp->channel();
        $this->channel->queue_declare(self::isolated(self::QUEUE), false, true, false, false);
        $this->channel->queue_purge(self::isolated(self::QUEUE));
    }

    protected function tearDown(): void
    {
        $this->channel->close();
    }

    private function consumer(int $maxAttempts = 5, ?\PDO $pdo = null, ?RecordingLogger $logger = null): AmqpConsumer
    {
        $pdo ??= self::$pdo;
        $dispatch = function (IncomingMessage $incoming): void {
            if ($this->failingDispatch !== null) {
                ($this->failingDispatch)($incoming);
            }
            $this->dispatched[] = $incoming;
        };

        return new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::isolated(self::QUEUE), prefetch: 8),
            processor: new DeliveryProcessor(
                deserializer: new JsonDeserializer(),
                dispatcher: new CallableDispatcher($dispatch),
                transaction: new PdoTransaction($pdo),
                deduplication: new PostgreSqlDeduplicationStore($pdo),
                retryPolicy: new ExponentialRetryPolicy(
                    maxAttempts: $maxAttempts,
                    backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
                ),
                deadLetters: new PostgreSqlDeadLetterStore($pdo),
                name: 'orders_consumer',
                logger: $logger ?? new RecordingLogger(),
            ),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function publish(string $payloadJson, array $metadata, string $messageId = 'm-1'): void
    {
        $this->channel->basic_publish(
            new AMQPMessage($payloadJson, [
                'content_type' => 'application/json',
                'message_id' => $messageId,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                // The envelope as individual x-message-* headers, exactly as
                // the relay sends it — that is the wire contract.
                'application_headers' => new AMQPTable(MetadataHeader::envelope(
                    (string) $metadata['message_id'],
                    (string) $metadata['message_name'],
                    (int) $metadata['created_at'],
                )),
            ]),
            '',
            self::isolated(self::QUEUE),
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} [payloadJson, metadata]
     */
    private function message(string $messageId = 'm-1', string $name = 'order.placed'): array
    {
        return [
            (string) json_encode([
                'order_id' => 'o-77',
                'total_cents' => 4999,
            ]),
            [
                'message_id' => $messageId,
                'message_name' => $name,
                'created_at' => EpochMillis::now(),
            ],
        ];
    }

    public function testADatabaseFailureStopsTheConsumerAndTheDeliveryIsRedelivered(): void
    {
        $pdo = self::newConnection();
        $statement = $pdo->query('SELECT pg_backend_pid()');
        self::assertNotFalse($statement);
        $pid = (int) $statement->fetchColumn();
        self::$pdo->exec("SELECT pg_terminate_backend({$pid})"); // the consumer's database goes away
        [$payload, $metadata] = $this->message();
        $this->publish($payload, $metadata);

        $logger = new RecordingLogger();
        try {
            $this->consumer(pdo: $pdo, logger: $logger)->run(messageLimit: 1, idleTimeoutSec: 2);
            self::fail('a database failure must stop the consumer');
        } catch (\PDOException) {
        }

        $errors = array_filter($logger->records, static fn (array $r): bool => $r['level'] === LogLevel::ERROR);
        self::assertCount(1, $errors, 'the database failure is reported before the process stops');
        self::assertStringContainsString('database failure', (string) array_values($errors)[0]['message']);

        self::assertSame([], $this->dispatched);
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM message_deduplication'));
        self::assertSame(0, self::fetchInt('SELECT COUNT(*) FROM dead_letters'), 'not a handler failure: no retry, no DLQ');

        // The delivery was never acked: closing the channel returns it to the queue.
        $this->channel->close();
        $this->channel = self::$amqp->channel();
        [, $ready] = $this->channel->queue_declare(self::isolated(self::QUEUE), true);
        self::assertSame(1, $ready);
    }

    public function testDeliveryIsDispatchedAndDeduplicationIsRecorded(): void
    {
        [$body, $meta] = $this->message('m-1');
        $this->publish($body, $meta);

        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched);
        $incoming = $this->dispatched[0];
        self::assertSame('o-77', $incoming->payload['order_id']);
        self::assertSame(4999, $incoming->payload['total_cents']);
        self::assertSame('m-1', $incoming->messageId);
        self::assertSame('order.placed', $incoming->messageName);

        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM message_deduplication WHERE message_id = 'm-1'"),
            'dedup entry committed with the handler',
        );
    }

    public function testDuplicateDeliveryIsAckedButSkipsTheHandler(): void
    {
        [$body, $meta] = $this->message('m-1');
        $this->publish($body, $meta);
        $this->publish($body, $meta);

        $this->consumer()
            ->run(messageLimit: 2, idleTimeoutSec: 10);

        self::assertCount(1, $this->dispatched, 'second delivery of the same message id must be skipped');
    }

    public function testMalformedBodyDeadLettersImmediatelyWithoutRetry(): void
    {
        $this->publish('{{{not json', $this->message('m-bad')[1], messageId: 'm-bad');

        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(0, $this->dispatched);
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE source = '".self::isolated(self::QUEUE)."'"));
    }

    public function testDispatchExceptionExhaustsRetryBudgetAndDeadLetters(): void
    {
        // The broker is message-name-agnostic now: routing lives downstream.
        // A dispatch exception surfaces here; with maxAttempts=1 the retry
        // budget is exhausted immediately and the message goes to the DLQ.
        $this->failingDispatch = static fn (IncomingMessage $m) => throw new RuntimeException(
            "nothing routes '{$m->messageName}'"
        );

        [$body, $meta] = $this->message('m-1', name: 'nobody.handles.this');
        $this->publish($body, $meta);

        $this->consumer(maxAttempts: 1)
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(0, $this->dispatched);
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE message_name = 'nobody.handles.this'"),
        );
    }

    public function testHandlerFailureRetriesViaWaitQueueThenDeadLetters(): void
    {
        $attempts = 0;
        $this->failingDispatch = static function () use (&$attempts): void {
            ++$attempts;
            throw new RuntimeException('handler always fails');
        };

        [$body, $meta] = $this->message('m-1');
        $this->publish($body, $meta);

        // maxAttempts=2, backoff fixed at 100ms: attempt 1 → wait queue →
        // redelivered after TTL → attempt 2 → exhausted → DLQ.
        $this->consumer(maxAttempts: 2)
            ->run(messageLimit: 2, idleTimeoutSec: 10);

        self::assertSame(2, $attempts, 'handler must be attempted exactly maxAttempts times');
        self::assertCount(0, $this->dispatched);
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE message_id = 'm-1' AND attempts = 2"),
        );
        self::assertSame(
            0,
            self::fetchInt("SELECT COUNT(*) FROM message_deduplication WHERE message_id = 'm-1'"),
            'failed handling must not leave a dedup entry (atomic rollback)',
        );
    }
}
