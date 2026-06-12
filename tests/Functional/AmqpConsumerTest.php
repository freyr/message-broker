<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\MessageBroker\Consumer\Binding;
use Freyr\MessageBroker\Consumer\HandlerRegistry;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Consumer\PdoDeduplicationStore;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Tests\Fixtures\OrderPlacedDto;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class AmqpConsumerTest extends FunctionalTestCase
{
    private const string QUEUE = 'mb_consumer_test_q';

    private static AMQPStreamConnection $amqp;
    private AMQPChannel $channel;

    /** @var list<array{message: OrderPlacedDto, envelope: IncomingMessage}> */
    private array $handled = [];

    private ?\Closure $failingHandler = null;

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
        $this->handled = [];
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
        $platform = new MySqlPlatform();
        $handler = function (OrderPlacedDto $message, IncomingMessage $envelope): void {
            if ($this->failingHandler !== null) {
                ($this->failingHandler)($message, $envelope);
            }
            $this->handled[] = [
                'message' => $message,
                'envelope' => $envelope,
            ];
        };

        return new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::QUEUE, prefetch: 8),
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
            deduplication: new PdoDeduplicationStore(self::$pdo, $platform),
            retryPolicy: new AmqpRetryPolicy(
                maxAttempts: $maxAttempts,
                backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
            ),
            deadLetters: new PdoDeadLetterStore(self::$pdo, $platform),
            name: 'orders_consumer',
        );
    }

    private function publish(string $body, string $messageId = 'm-1'): void
    {
        $this->channel->basic_publish(
            new AMQPMessage($body, [
                'content_type' => 'application/json',
                'message_id' => $messageId,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]),
            '',
            self::QUEUE,
        );
    }

    private function document(string $messageId = 'm-1', string $name = 'order.placed'): string
    {
        return (string) json_encode([
            'metadata' => [
                'message_id' => $messageId,
                'message_name' => $name,
                'created_at' => EpochMillis::now(),
            ],
            'payload' => [
                'order_id' => 'o-77',
                'total_cents' => 4999,
            ],
        ]);
    }

    public function testConsumesDeliveryIntoTypedHandlerAndRecordsDeduplication(): void
    {
        $this->publish($this->document('m-1'));

        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(1, $this->handled);
        $dto = $this->handled[0]['message'];
        self::assertSame('o-77', $dto->orderId);
        self::assertSame(4999, $dto->totalCents);
        self::assertSame('m-1', $this->handled[0]['envelope']->messageId);
        self::assertSame('order.placed', $this->handled[0]['envelope']->messageName);

        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM message_deduplication WHERE message_id = 'm-1'"),
            'dedup entry committed with the handler',
        );
    }

    public function testDuplicateDeliveryIsAckedButSkipsTheHandler(): void
    {
        $this->publish($this->document('m-1'));
        $this->publish($this->document('m-1'));

        $this->consumer()
            ->run(messageLimit: 2, idleTimeoutSec: 10);

        self::assertCount(1, $this->handled, 'second delivery of the same message id must be skipped');
    }

    public function testMalformedBodyDeadLettersImmediatelyWithoutRetry(): void
    {
        $this->publish('{{{not json', messageId: 'm-bad');

        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(0, $this->handled);
        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM dead_letters'));
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE source = '".self::QUEUE."'"));
    }

    public function testUnknownMessageNameDeadLetters(): void
    {
        $this->publish($this->document('m-1', name: 'nobody.handles.this'));

        $this->consumer()
            ->run(messageLimit: 1, idleTimeoutSec: 10);

        self::assertCount(0, $this->handled);
        self::assertSame(
            1,
            self::fetchInt("SELECT COUNT(*) FROM dead_letters WHERE message_name = 'nobody.handles.this'"),
        );
    }

    public function testHandlerFailureRetriesViaWaitQueueThenDeadLetters(): void
    {
        $this->failingHandler = static fn () => throw new RuntimeException('handler always fails');
        $attempts = 0;
        $this->failingHandler = static function () use (&$attempts): void {
            ++$attempts;
            throw new RuntimeException('handler always fails');
        };

        $this->publish($this->document('m-1'));

        // maxAttempts=2, backoff fixed at 100ms: attempt 1 → wait queue →
        // redelivered after TTL → attempt 2 → exhausted → DLQ.
        $this->consumer(maxAttempts: 2)
            ->run(messageLimit: 2, idleTimeoutSec: 10);

        self::assertSame(2, $attempts, 'handler must be attempted exactly maxAttempts times');
        self::assertCount(0, $this->handled);
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
