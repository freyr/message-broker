<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Unit;

use Freyr\MessageBroker\Adapter\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Adapter\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Adapter\Amqp\WaitQueuePublisher;
use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\DeliveryProcessor;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Retry\RetryDecision;
use Freyr\MessageBroker\Retry\RetryPolicy;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Testing\RecordingTransaction;
use Freyr\MessageBroker\Testing\StubDeduplicationStore;
use PhpAmqpLib\Exception\AMQPTimeoutException;
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

    private RecordingLogger $logger;

    /** The return of a graceful stop: cancel, then one nack of all unacked deliveries with requeue. */
    private const array GRACEFUL_RETURN = [
        ['basic_cancel', 'ctag', false, false],
        ['basic_nack', 0, true, true],
    ];

    protected function setUp(): void
    {
        $this->setUpChannel();
        $this->transaction = new RecordingTransaction();
        $this->dedup = new StubDeduplicationStore();
        $this->deadLetterStore = new InMemoryDeadLetterStore();
        $this->logger = new RecordingLogger();
    }

    /** @return list<list<mixed>> the return of a failure: cancel, reject of the delivery in work, nack of the others */
    private static function rejectionReturn(int $tagInWork): array
    {
        return [
            ['basic_cancel', 'ctag', false, false],
            ['basic_reject', $tagInWork, true],
            ['basic_nack', 0, true, true],
        ];
    }

    private static function deserializerThatFailsOn(string $body, Throwable $error): Deserializer
    {
        return new class($body, $error) implements Deserializer {
            public function __construct(
                private readonly string $body,
                private readonly Throwable $error,
            ) {}

            public function deserialize(string $bytes, array $headers = []): IncomingMessage
            {
                if ($bytes === $this->body) {
                    throw $this->error;
                }

                return (new JsonDeserializer())->deserialize($bytes, $headers);
            }
        };
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
            logger: $this->logger,
        );
    }

    // run loop

    public function testRunSetsPrefetchConsumesTheQueueAndCancelsTheConsumer(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer(prefetch: 7)->run(messageLimit: 1);

        self::assertSame([[0, 7, false]], $this->qosCalls, 'prefetch is per consumer, not per channel');
        self::assertSame(self::QUEUE, $this->consumedQueue);
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
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
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls, 'an idle stop is a graceful stop');
        self::assertSame([], $this->logger->records);
    }

    public function testRunStopsAfterTheMessageLimit(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2'), $this->delivery(3, 'm-3')];

        $this->consumer()->run(messageLimit: 2);

        self::assertSame([1, 2], $this->acked);
        self::assertCount(1, $this->pending);
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls, 'the buffered delivery returns with no count');
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
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testSigtermAndSigintStopTheConsumerGracefully(): void
    {
        foreach ([SIGTERM, SIGINT] as $signal) {
            $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];
            $this->acked = [];
            $this->returnCalls = [];
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
            self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls, "signal {$signal} is a graceful stop");
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
        self::assertCount(1, $this->deadLetterStore->list(new DeadLetterFilter()));
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
        self::assertCount(1, $this->deadLetterStore->list(new DeadLetterFilter()));
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
        self::assertSame([], $this->deadLetterStore->list(new DeadLetterFilter()));
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
        self::assertSame(self::rejectionReturn(1), $this->returnCalls, 'the delivery in work is rejected, the others nacked');
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
        self::assertSame(self::rejectionReturn(1), $this->returnCalls, 'the delivery in work returns with a count');
    }

    // the return rule

    public function testAThrowableFromProcessRejectsTheDeliveryInWorkAndNacksTheOthers(): void
    {
        $error = new RuntimeException('registry down');
        $this->pending = [
            $this->delivery(1, 'm-1'),
            $this->delivery(2, 'm-2', body: 'boom'),
            $this->delivery(3, 'm-3'),
        ];

        try {
            $this->consumer(deserializer: self::deserializerThatFailsOn('boom', $error))->run();
            self::fail('the failure must end run()');
        } catch (Throwable $e) {
            self::assertSame($error, $e);
        }

        self::assertSame([1], $this->acked);
        self::assertSame(self::rejectionReturn(2), $this->returnCalls);
        self::assertSame([], $this->logger->records);
    }

    public function testAConfirmTimeoutOfTheRetryTakesTheRejectionPathAndIsNotAnIdleStop(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->confirmTimesOut = true;
        $this->pending = [$this->delivery(4, 'm-4'), $this->delivery(5, 'm-5')];

        try {
            $this->consumer()->run(idleTimeoutSec: 2);
            self::fail('a confirm timeout must end run() with the exception');
        } catch (AMQPTimeoutException $e) {
            self::assertSame('confirm timeout', $e->getMessage());
        }

        self::assertSame([], $this->acked);
        self::assertCount(1, $this->pending, 'the loop does not continue after the failure');
        self::assertSame(self::rejectionReturn(4), $this->returnCalls);
    }

    public function testAnIdleTimeoutAfterAHandledDeliveryIsStillAGracefulStop(): void
    {
        $this->timeoutWhenEmpty = true;
        $this->pending = [$this->delivery(1, 'm-1')];

        $this->consumer()->run(idleTimeoutSec: 2);

        self::assertSame([1], $this->acked);
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls, 'no delivery is in work after an acked one');
    }

    /** @return iterable<string, array{string, list<list<mixed>>}> */
    public static function failuresInsideTheRejection(): iterable
    {
        yield 'cancel' => ['basic_cancel', [['basic_cancel', 'ctag', false, false]]];
        yield 'reject' => ['basic_reject', [['basic_cancel', 'ctag', false, false], ['basic_reject', 1, true]]];
        yield 'nack' => ['basic_nack', self::rejectionReturn(1)];
    }

    /** @param list<list<mixed>> $expectedCalls */
    #[DataProvider('failuresInsideTheRejection')]
    public function testAFailureInsideTheRejectionIsLoggedAndTheOriginalErrorGoesOn(
        string $method,
        array $expectedCalls,
    ): void {
        $error = new RuntimeException('registry down');
        $returnFailure = new RuntimeException('connection lost');
        $this->failures[$method] = $returnFailure;
        $this->pending = [$this->delivery(1, 'm-1', body: 'boom')];

        try {
            $this->consumer(deserializer: self::deserializerThatFailsOn('boom', $error))->run();
            self::fail('the failure must end run()');
        } catch (Throwable $e) {
            self::assertSame($error, $e, 'the return never replaces the original error');
        }

        self::assertSame($expectedCalls, $this->returnCalls, 'nothing more after the failed call');
        self::assertSame([[
            'level' => 'error',
            'message' => 'AMQP consumer could not return its unacked deliveries; the broker returns them at the close',
            'context' => [
                'queue' => self::QUEUE,
                'exception' => $returnFailure,
            ],
        ]], $this->logger->records);
    }

    public function testAFailureOfTheNackAtAGracefulStopIsTheErrorOfRun(): void
    {
        $nackFailure = new RuntimeException('connection lost');
        $this->failures['basic_nack'] = $nackFailure;
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        try {
            $this->consumer()->run(messageLimit: 1);
            self::fail('a failed graceful return must end run() with its error');
        } catch (Throwable $e) {
            self::assertSame($nackFailure, $e);
        }

        self::assertSame([1], $this->acked);
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
        self::assertSame([], $this->logger->records);
    }

    public function testAThrowableFromWaitWithNoDeliveryInWorkGetsTheGracefulReturn(): void
    {
        $error = new RuntimeException('server cancelled the consumer');
        $this->failures['wait'] = $error;
        $this->pending = [$this->delivery(1, 'm-1')];

        try {
            $this->consumer()->run();
            self::fail('the failure must end run()');
        } catch (Throwable $e) {
            self::assertSame($error, $e);
        }

        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls, 'no reject: no delivery is in work');
        self::assertSame([], $this->logger->records);
    }

    public function testAFailedReturnAfterAThrowableFromWaitIsLoggedAndTheOriginalErrorGoesOn(): void
    {
        $error = new RuntimeException('server cancelled the consumer');
        $nackFailure = new RuntimeException('connection lost');
        $this->failures['wait'] = $error;
        $this->failures['basic_nack'] = $nackFailure;
        $this->pending = [$this->delivery(1, 'm-1')];

        try {
            $this->consumer()->run();
            self::fail('the failure must end run()');
        } catch (Throwable $e) {
            self::assertSame($error, $e);
        }

        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame($nackFailure, $this->logger->records[0]['context']['exception']);
    }

    /** @return iterable<string, array{string}> */
    public static function setUpFailures(): iterable
    {
        yield 'basic_qos' => ['basic_qos'];
        yield 'basic_consume' => ['basic_consume'];
    }

    #[DataProvider('setUpFailures')]
    public function testAFailureBeforeTheConsumerTagSendsNoReturn(string $method): void
    {
        $error = new RuntimeException('channel closed');
        $this->failures[$method] = $error;
        $this->pending = [$this->delivery(1, 'm-1')];

        try {
            $this->consumer()->run();
            self::fail('the failure must end run()');
        } catch (Throwable $e) {
            self::assertSame($error, $e);
        }

        self::assertSame([], $this->returnCalls);
        self::assertSame([], $this->logger->records);
    }

    public function testTheLoggerIsOptional(): void
    {
        $this->failures['basic_reject'] = new RuntimeException('connection lost');
        $error = new RuntimeException('registry down');
        $this->pending = [$this->delivery(1, 'm-1', body: 'boom')];
        $consumer = new AmqpConsumer(
            $this->channel,
            new AmqpQueueConfig(self::QUEUE),
            new DeliveryProcessor(
                self::deserializerThatFailsOn('boom', $error),
                new CallableDispatcher(static function (IncomingMessage $incoming): void {}),
                $this->transaction,
                $this->dedup,
                new ExponentialRetryPolicy(),
                $this->deadLetterStore,
            ),
        );

        try {
            $consumer->run();
            self::fail('the failure must end run()');
        } catch (Throwable $e) {
            self::assertSame($error, $e);
        }
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

    public function testMappingReadsTheDeliveryCountHeaderOfTheWaitQueuePublisher(): void
    {
        $delivery = $this->consumer()->deliveryFrom($this->delivery(1, 'm-1', [
            WaitQueuePublisher::DELIVERY_COUNT_HEADER => 7,
        ]));

        self::assertSame(7, $delivery->deliveryCount);
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
