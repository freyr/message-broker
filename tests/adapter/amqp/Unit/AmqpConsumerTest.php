<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Unit;

use Freyr\MessageBroker\Adapter\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Retry\RetryDecision;
use Freyr\MessageBroker\Retry\RetryPolicy;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Testing\RecordingTransaction;
use Freyr\MessageBroker\Testing\StubDeduplicationStore;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The AMQP loop against a recording channel: run loop, message limit, the
 * mapping AMQPMessage → Delivery, and the wiring to a real DeliveryProcessor.
 * The algorithm itself is tested in DeliveryProcessorTest.
 */
final class AmqpConsumerTest extends TestCase
{
    use RecordsChannel;

    /** @var list<IncomingMessage> */
    private array $dispatched = [];

    private ?\Closure $failingDispatch = null;

    private ?RetryPolicy $retryPolicy = null;

    private RecordingTransaction $transaction;

    private StubDeduplicationStore $dedup;

    private InMemoryDeadLetterStore $deadLetterStore;

    protected function setUp(): void
    {
        $this->setUpChannel();
        $this->transaction = new RecordingTransaction();
        $this->dedup = new StubDeduplicationStore();
        $this->deadLetterStore = new InMemoryDeadLetterStore();
    }

    private function consumer(
        ?Deserializer $deserializer = null,
        int $maxAttempts = 3,
        int $prefetch = 7,
        int $confirmTimeoutSec = 9,
    ): AmqpConsumer {
        $processor = new DeliveryProcessor(
            deserializer: $deserializer ?? new JsonDeserializer(),
            dispatcher: new CallableDispatcher(function (IncomingMessage $incoming): void {
                if ($this->failingDispatch !== null) {
                    ($this->failingDispatch)($incoming);
                }
                $this->dispatched[] = $incoming;
            }),
            transaction: $this->transaction,
            deduplication: $this->dedup,
            retryPolicy: $this->retryPolicy ?? new ExponentialRetryPolicy(
                maxAttempts: $maxAttempts,
                backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
            ),
            deadLetters: $this->deadLetterStore,
            name: 'orders_consumer',
        );

        return new AmqpConsumer(
            channel: $this->channel,
            queue: new AmqpQueueConfig(self::QUEUE, prefetch: $prefetch),
            processor: $processor,
            confirmTimeoutSec: $confirmTimeoutSec,
        );
    }

    // run loop

    public function testRunSetsPrefetchConsumesTheQueueAndCancelsTheConsumer(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer(prefetch: 7)->run(messageLimit: 1);

        self::assertSame([[0, 7, false]], $this->qosCalls, 'prefetch is per consumer, not per channel');
        self::assertSame(self::QUEUE, $this->consumedQueue);
        self::assertTrue($this->cancelled);
    }

    public function testRunBlocksWithoutTimeoutWhenNoIdleTimeoutIsSet(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame([[null, false, 0]], $this->waitTimeouts, 'timeout 0 means block');
    }

    public function testRunPassesTheIdleTimeoutToTheChannelWait(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer()->run(messageLimit: 1, idleTimeoutSec: 3);

        self::assertSame([[null, false, 3]], $this->waitTimeouts);
    }

    public function testRunReturnsWhenTheQueueStaysSilentForTheIdleWindow(): void
    {
        $this->timeoutWhenEmpty = true;

        $this->consumer()->run(idleTimeoutSec: 2);

        self::assertCount(1, $this->waitTimeouts);
        self::assertTrue($this->cancelled);
    }

    public function testRunStopsAfterTheMessageLimit(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2'), $this->delivery(3, 'm-3')];

        $this->consumer()->run(messageLimit: 2);

        self::assertSame([1, 2], $this->acked);
        self::assertCount(1, $this->pending);
    }

    public function testStopEndsTheLoopAfterTheCurrentDelivery(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];
        $consumer = $this->consumer();
        $this->failingDispatch = function () use (&$consumer): void {
            $consumer->stop();
        };

        $consumer->run();

        self::assertSame([1], $this->acked, 'the second delivery is not consumed after stop()');
        self::assertCount(1, $this->pending);
        self::assertTrue($this->cancelled);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testSigtermAndSigintStopTheConsumerGracefully(): void
    {
        foreach ([SIGTERM, SIGINT] as $signal) {
            $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];
            $this->acked = [];
            $this->failingDispatch = static function () use ($signal): void {
                posix_kill(posix_getpid(), $signal);
            };
            $async = pcntl_async_signals();
            pcntl_async_signals(false); // run() itself must switch asynchronous delivery on

            try {
                $this->consumer()->run();
            } finally {
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGINT, SIG_DFL);
                pcntl_async_signals($async);
            }

            self::assertSame([1], $this->acked, "signal {$signal} stops the loop after the current delivery");
        }
    }

    // the handled counter: every acked delivery counts toward the message limit

    public function testADuplicateCountsTowardTheMessageLimit(): void
    {
        $this->dedup->duplicate = true;
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->pending);
    }

    public function testAMalformedDeliveryCountsTowardTheMessageLimit(): void
    {
        $this->pending = [$this->delivery(1, 'm-1', body: '{{{not json'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->pending);
        self::assertCount(1, $this->deadLetterStore->list());
    }

    public function testARetriedDeliveryCountsTowardTheMessageLimit(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->published);
        self::assertCount(1, $this->pending);
    }

    public function testADeadLetteredDeliveryCountsTowardTheMessageLimit(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [
            $this->delivery(1, 'm-1', [
                'x-attempt' => 3,
            ]),
            $this->delivery(2, 'm-2'),
        ];

        $this->consumer(maxAttempts: 3)->run(messageLimit: 1);

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->pending);
        self::assertCount(1, $this->deadLetterStore->list());
    }

    public function testADiscardedDeliveryCountsTowardTheMessageLimit(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->retryPolicy = new class() implements RetryPolicy {
            public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
            {
                return RetryDecision::discard();
            }
        };
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(messageLimit: 1);

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->pending);
    }

    // wiring: the processor reaches the channel through the per-delivery control

    public function testARetryReachesTheWaitQueueBeforeTheAck(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [$this->delivery(4, 'm-4')];

        $this->consumer(confirmTimeoutSec: 9)->run(messageLimit: 1);

        self::assertSame(['begin', 'rollback'], $this->transaction->calls);
        self::assertCount(1, $this->published);
        self::assertSame('orders.wait.100', $this->published[0]['routingKey']);
        self::assertSame(2, $this->published[0]['message']->get('application_headers')->getNativeData()['x-attempt']);
        self::assertSame([9], $this->pendingAckTimeouts, 'the republish is confirmed before the ack');
        self::assertSame([4], $this->acked);
        self::assertSame([], $this->deadLetterStore->list());
    }

    public function testTheConfirmTimeoutDefaultsToFiveSeconds(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [$this->delivery()];
        $consumer = new AmqpConsumer(
            $this->channel,
            new AmqpQueueConfig(self::QUEUE),
            new DeliveryProcessor(
                new JsonDeserializer(),
                new CallableDispatcher(fn (IncomingMessage $incoming) => ($this->failingDispatch)($incoming)),
                $this->transaction,
                $this->dedup,
                new ExponentialRetryPolicy(),
                $this->deadLetterStore,
            ),
        );

        $consumer->run(messageLimit: 1);

        self::assertSame([5], $this->pendingAckTimeouts);
    }

    public function testWaitQueueIsSetUpOncePerDelayAcrossDeliveries(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(messageLimit: 2);

        self::assertSame(1, $this->confirmSelects);
        self::assertCount(1, $this->declared);
        self::assertCount(2, $this->published);
    }

    public function testBrokerNackOfTheRepublishStopsTheConsumerWithoutAck(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->nackOnConfirm = true;
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        try {
            $this->consumer()->run(messageLimit: 5);
            self::fail('a nacked republish must stop the consumer');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('orders.wait.100', $e->getMessage());
        }

        self::assertSame([], $this->acked, 'the original delivery stays unacked');
        self::assertCount(1, $this->pending, 'the loop does not continue after the failure');
    }

    public function testATransientDeserializeFailureStopsTheConsumerWithoutAck(): void
    {
        $error = new RuntimeException('registry down');
        $deserializer = new class($error) implements Deserializer {
            public function __construct(
                private readonly RuntimeException $error,
            ) {}

            public function deserialize(string $bytes, array $headers = []): IncomingMessage
            {
                throw $this->error;
            }
        };
        $this->pending = [$this->delivery()];

        try {
            $this->consumer(deserializer: $deserializer)->run(messageLimit: 1);
            self::fail('a transient failure must propagate');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e);
        }

        self::assertSame([], $this->acked);
        self::assertFalse($this->cancelled, 'the consumer is not cancelled: the delivery is requeued on connection close');
    }

    // mapping AMQPMessage -> Delivery

    public function testMappingCarriesBodyHeadersSourceAndFallbackMessageId(): void
    {
        $message = $this->delivery(1, 'm-1', [
            'x-custom' => 'kept',
        ], body: 'raw-body', propertyMessageId: 'prop-id');

        $delivery = $this->consumer()->deliveryFrom($message);

        self::assertSame('raw-body', $delivery->body);
        self::assertSame(self::QUEUE, $delivery->source);
        self::assertSame('prop-id', $delivery->fallbackMessageId);
        self::assertSame('kept', $delivery->headers['x-custom']);
        self::assertSame('m-1', $delivery->headers['x-message-id']);
        self::assertSame(1, $delivery->attempt);
        self::assertNull($delivery->deliveryCount);
    }

    public function testMappingOfAMessageWithoutPropertiesUsesDefaults(): void
    {
        $delivery = $this->consumer()->deliveryFrom(new AMQPMessage('b'));

        self::assertSame([], $delivery->headers);
        self::assertNull($delivery->fallbackMessageId);
        self::assertSame(1, $delivery->attempt);
        self::assertNull($delivery->deliveryCount);
    }

    public function testMappingKeepsTheAttemptHeader(): void
    {
        $delivery = $this->consumer()->deliveryFrom($this->delivery(1, 'm-1', [
            'x-attempt' => 4,
        ]));

        self::assertSame(4, $delivery->attempt);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidAttemptHeaders(): iterable
    {
        yield 'string' => ['3'];
        yield 'zero' => [0];
        yield 'negative' => [-4];
        yield 'float' => [2.0];
    }

    #[DataProvider('invalidAttemptHeaders')]
    public function testAnInvalidAttemptHeaderCountsAsTheFirstAttempt(mixed $header): void
    {
        $delivery = $this->consumer()->deliveryFrom($this->delivery(1, 'm-1', [
            'x-attempt' => $header,
        ]));

        self::assertSame(1, $delivery->attempt);
    }

    public function testMappingCarriesTheDeliveryCountOfTheTransport(): void
    {
        $delivery = $this->consumer()->deliveryFrom($this->delivery(1, 'm-1', [
            'x-delivery-count' => 3,
        ]));

        self::assertSame(3, $delivery->deliveryCount);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDeliveryCounts(): iterable
    {
        yield 'string' => ['3'];
        yield 'float' => [2.0];
        yield 'bool' => [true];
    }

    #[DataProvider('invalidDeliveryCounts')]
    public function testADeliveryCountThatIsNotAnIntegerIsIgnored(mixed $header): void
    {
        $delivery = $this->consumer()->deliveryFrom($this->delivery(1, 'm-1', [
            'x-delivery-count' => $header,
        ]));

        self::assertNull($delivery->deliveryCount);
    }

    public function testAPropertyMessageIdThatIsNotAStringIsIgnored(): void
    {
        $message = new AMQPMessage('b', [
            'message_id' => 12,
            'application_headers' => new AMQPTable([]),
        ]);

        self::assertNull($this->consumer()->deliveryFrom($message)->fallbackMessageId);
    }
}
