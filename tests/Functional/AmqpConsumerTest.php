<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

final class AmqpConsumerTest extends FunctionalTestCase
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
        $channel->queue_delete(self::QUEUE);
        $channel->queue_delete(self::QUEUE.'.wait.100');
        $channel->close();
        self::$amqp->close();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatched = [];
        $this->channel = self::$amqp->channel();
        $this->channel->queue_declare(self::QUEUE, false, true, false, false);
        $this->channel->queue_purge(self::QUEUE);
    }

    protected function tearDown(): void
    {
        $this->channel->close();
    }

    private function consumer(int $maxAttempts = 5): AmqpConsumer
    {
        $platform = static::platform();
        $dispatch = function (IncomingMessage $incoming): void {
            if ($this->failingDispatch !== null) {
                ($this->failingDispatch)($incoming);
            }
            $this->dispatched[] = $incoming;
        };

        return new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::QUEUE, prefetch: 8),
            deserializer: new JsonDeserializer(),
            dispatcher: new CallableDispatcher($dispatch),
            pdo: self::$pdo,
            deduplication: new PdoDeduplicationStore(self::$pdo, $platform),
            retryPolicy: new AmqpRetryPolicy(
                maxAttempts: $maxAttempts,
                backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
            ),
            deadLetters: new PdoDeadLetterStore(self::$pdo, $platform),
            name: 'orders_consumer',
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
                // Explode the envelope into individual x-message-* headers,
                // exactly as the relay does — that is the wire contract.
                'application_headers' => new AMQPTable(MetadataHeader::explode($metadata)),
            ]),
            '',
            self::QUEUE,
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
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE source = '".self::QUEUE."'"));
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
