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
use Freyr\MessageBroker\Worker\WorkerLimits;
use Freyr\MessageBroker\Worker\WorkerStopReason;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Throwable;

/**
 * The AMQP loop against a recording channel: run loop, worker limits, the
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

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    private static function mockClock(): MockClock
    {
        $clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($clock);

        return $clock;
    }

    /** @return list<string> the reason of each limit stop that the consumer logged */
    private function loggedStopReasons(): array
    {
        $reasons = [];
        foreach ($this->logger->records as $record) {
            if ($record['message'] === 'Worker limit reached; the worker stops') {
                self::assertSame(LogLevel::INFO, $record['level']);
                self::assertIsString($record['context']['reason']);
                $reasons[] = $record['context']['reason'];
            }
        }

        return $reasons;
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

        $this->consumer(prefetch: 7)->run(new WorkerLimits(maxMessages: 1));

        self::assertSame([[0, 7, false]], $this->qosCalls, 'prefetch is per consumer, not per channel');
        self::assertSame(self::QUEUE, $this->consumedQueue);
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
    }

    public function testRunBlocksWithoutTimeoutWhenNoIdleTimeoutIsSet(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer()->run(new WorkerLimits(maxMessages: 1));

        self::assertSame([[null, false, 0]], $this->waitTimeouts, 'timeout 0 means block');
    }

    public function testRunPassesTheIdleTimeoutToTheChannelWait(): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer()->run(new WorkerLimits(maxMessages: 1), idleTimeoutSec: 3);

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

        $this->consumer()->run(new WorkerLimits(maxMessages: 2));

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

    // worker limits: the wait timeout

    public function testTheWaitTimeoutIsTheTimeLeftWhenItIsBelowTheIdleTimeout(): void
    {
        self::mockClock();
        $this->pending = [$this->delivery()];

        $this->consumer()->run(new WorkerLimits(maxMessages: 1, maxSeconds: 3), idleTimeoutSec: 5);

        self::assertSame([[null, false, 3.0]], $this->waitTimeouts);
    }

    public function testTheWaitTimeoutIsTheTimeLeftWhenItEqualsTheIdleTimeoutAndItsEndIsATimeLimitStop(): void
    {
        self::mockClock();
        $this->timeoutWhenEmpty = true;

        $this->consumer()->run(new WorkerLimits(maxSeconds: 5), idleTimeoutSec: 5);

        self::assertSame([[null, false, 5.0]], $this->waitTimeouts);
        self::assertSame(['time limit'], $this->loggedStopReasons());
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
    }

    public function testTheWaitTimeoutIsTheIdleTimeoutWhenItIsBelowTheTimeLeftAndItsEndIsAnIdleStop(): void
    {
        self::mockClock();
        $this->timeoutWhenEmpty = true;

        $this->consumer()->run(new WorkerLimits(maxSeconds: 10), idleTimeoutSec: 3);

        self::assertSame([[null, false, 3]], $this->waitTimeouts);
        self::assertSame([], $this->logger->records, 'an idle stop logs no limit');
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
    }

    /** @return iterable<string, array{int|null}> */
    public static function noIdleTimeouts(): iterable
    {
        yield 'null' => [null];
        yield 'zero' => [0];
    }

    #[DataProvider('noIdleTimeouts')]
    public function testWithATimeLimitAndNoIdleTimeoutTheWaitTimeoutIsTheTimeLeft(?int $idleTimeoutSec): void
    {
        self::mockClock();
        $this->pending = [$this->delivery()];

        $this->consumer()->run(new WorkerLimits(maxMessages: 1, maxSeconds: 10), $idleTimeoutSec);

        self::assertSame([[null, false, 10.0]], $this->waitTimeouts, 'never 0, which blocks');
    }

    #[DataProvider('noIdleTimeouts')]
    public function testWithNoTimeLimitAndNoIdleTimeoutTheWaitBlocks(?int $idleTimeoutSec): void
    {
        $this->pending = [$this->delivery()];

        $this->consumer()->run(new WorkerLimits(maxMessages: 1), $idleTimeoutSec);

        self::assertSame([[null, false, 0]], $this->waitTimeouts);
    }

    public function testTheTimeLeftIsReadBeforeEachWait(): void
    {
        $clock = self::mockClock();
        $this->onWait = static function () use ($clock): void {
            $clock->sleep(2.5);
        };
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(new WorkerLimits(maxMessages: 2, maxSeconds: 10));

        self::assertSame([[null, false, 10.0], [null, false, 7.5]], $this->waitTimeouts);
    }

    // worker limits: the time limit

    public function testATimeoutOfAWaitThatTheTimeLimitBoundsIsATimeLimitStopWithNoSecondClockRead(): void
    {
        $clock = self::mockClock();
        $this->timeoutWhenEmpty = true;
        $this->onWait = static function () use ($clock): void {
            $clock->sleep(9.999); // the wait ends a fraction before the limit
        };

        $this->consumer()->run(new WorkerLimits(maxSeconds: 10));

        self::assertCount(1, $this->waitTimeouts, 'no second wait for the last millisecond');
        self::assertSame(['time limit'], $this->loggedStopReasons());
        self::assertSame(9.999, $this->logger->records[0]['context']['elapsedSeconds']);
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls, 'a limit stop is a graceful stop');
    }

    public function testATimeLimitReachedBeforeTheFirstWaitStopsWithNoWait(): void
    {
        $clock = self::mockClock();
        $this->onConsume = static function () use ($clock): void {
            $clock->sleep(10);
        };
        $this->pending = [$this->delivery()];

        $this->consumer()->run(new WorkerLimits(maxSeconds: 10), idleTimeoutSec: 3);

        self::assertSame([], $this->waitTimeouts);
        self::assertSame([], $this->acked);
        self::assertSame(['time limit'], $this->loggedStopReasons());
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
    }

    public function testATimeLimitReachedDuringADeliveryStopsAfterIt(): void
    {
        $clock = self::mockClock();
        $this->failingDispatch = static function () use ($clock): void {
            $clock->sleep(10);
        };
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(new WorkerLimits(maxSeconds: 10));

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->pending);
        self::assertSame(['time limit'], $this->loggedStopReasons());
    }

    public function testAConfirmTimeoutDuringAWaitThatTheTimeLimitBoundsTakesTheRejectionPath(): void
    {
        self::mockClock();
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->confirmTimesOut = true;
        $this->pending = [$this->delivery(4, 'm-4'), $this->delivery(5, 'm-5')];

        try {
            $this->consumer()->run(new WorkerLimits(maxSeconds: 10));
            self::fail('a confirm timeout must end run() with the exception');
        } catch (AMQPTimeoutException $e) {
            self::assertSame('confirm timeout', $e->getMessage());
        }

        self::assertSame([[null, false, 10.0]], $this->waitTimeouts, 'the wait that the time limit bounds');
        self::assertSame([], $this->acked);
        self::assertCount(1, $this->pending);
        self::assertSame(self::rejectionReturn(4), $this->returnCalls);
        self::assertSame([], $this->loggedStopReasons(), 'no time limit stop');
    }

    // worker limits: memory, failures and the log line

    public function testAMemoryLimitOfOneStopsAfterTheFirstDelivery(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(new WorkerLimits(maxMemoryBytes: 1));

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->pending);
        self::assertSame(['memory limit'], $this->loggedStopReasons());
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
    }

    public function testAMemoryLimitOfPhpIntMaxDoesNotStop(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(new WorkerLimits(maxMemoryBytes: PHP_INT_MAX));

        self::assertSame([1, 2], $this->acked);
        self::assertSame([], $this->logger->records);
    }

    /** @return iterable<string, array{\Closure(self): void}> */
    public static function handlerFailures(): iterable
    {
        yield 'retried' => [static function (self $test): void {
            $test->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        }];
        yield 'dead-lettered' => [static function (self $test): void {
            $test->failingDispatch = static fn () => throw new RuntimeException('handler failed');
            $test->retryPolicy = new class() implements RetryPolicy {
                public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
                {
                    return RetryDecision::deadLetter();
                }
            };
        }];
        yield 'discarded' => [static function (self $test): void {
            $test->failingDispatch = static fn () => throw new RuntimeException('handler failed');
            $test->retryPolicy = new class() implements RetryPolicy {
                public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
                {
                    return RetryDecision::discard();
                }
            };
        }];
    }

    /** @param \Closure(self): void $arrange */
    #[DataProvider('handlerFailures')]
    public function testTheFailureLimitCountsAHandlerFailure(\Closure $arrange): void
    {
        $arrange($this);
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2'), $this->delivery(3, 'm-3')];

        $this->consumer()->run(new WorkerLimits(maxFailures: 2));

        self::assertSame([1, 2], $this->acked);
        self::assertCount(1, $this->pending);
        self::assertSame(['failure limit'], $this->loggedStopReasons());
        $stop = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => $record['level'] === LogLevel::INFO,
        ));
        self::assertSame(2, $stop[0]['context']['failures']);
        self::assertSame(2, $stop[0]['context']['messages']);
    }

    /** @return iterable<string, array{\Closure(self): void}> */
    public static function outcomesThatAreNoHandlerFailure(): iterable
    {
        yield 'dispatched' => [static function (self $test): void {}];
        yield 'deduplicated' => [static function (self $test): void {
            $test->dedup->duplicate = true;
        }];
        yield 'malformed' => [static function (self $test): void {
            $test->pending = [$test->delivery(1, 'm-1', body: '{{{not json'), $test->delivery(2, 'm-2', body: '{{{')];
        }];
    }

    /** @param \Closure(self): void $arrange */
    #[DataProvider('outcomesThatAreNoHandlerFailure')]
    public function testTheFailureLimitDoesNotCountAnOutcomeThatIsNoHandlerFailure(\Closure $arrange): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];
        $arrange($this);

        $this->consumer()->run(new WorkerLimits(maxFailures: 1));

        self::assertSame([1, 2], $this->acked);
        self::assertSame([], $this->loggedStopReasons());
    }

    public function testALimitStopLogsTheReasonTheCountsTheElapsedTimeAndTheMemory(): void
    {
        $clock = self::mockClock();
        $this->onWait = static function () use ($clock): void {
            $clock->sleep(2);
        };
        $this->failingDispatch = static function (IncomingMessage $incoming): void {
            if ($incoming->messageId === 'm-2') {
                throw new RuntimeException('handler failed');
            }
        };
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2'), $this->delivery(3, 'm-3')];

        $this->consumer()->run(new WorkerLimits(maxMessages: 2));

        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::INFO, $record['level']);
        self::assertSame('Worker limit reached; the worker stops', $record['message']);
        $context = $record['context'];
        self::assertSame(
            ['queue', 'reason', 'messages', 'failures', 'elapsedSeconds', 'memoryBytes'],
            array_keys($context),
        );
        self::assertSame(self::QUEUE, $context['queue']);
        self::assertSame(WorkerStopReason::MessageLimit->value, $context['reason']);
        self::assertSame(2, $context['messages']);
        self::assertSame(1, $context['failures']);
        self::assertSame(4.0, $context['elapsedSeconds'], 'a float also for whole seconds');
        self::assertIsInt($context['memoryBytes']);
        self::assertGreaterThan(0, $context['memoryBytes']);
    }

    public function testALimitStopAndStopTakeTheSameCodeAfterTheLoop(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];
        $this->consumer()->run(new WorkerLimits(maxMessages: 1));
        $afterLimit = [$this->acked, $this->returnCalls];

        $this->setUpChannel();
        $this->acked = [];
        $this->returnCalls = [];
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];
        $consumer = $this->consumer();
        $this->failingDispatch = static function () use (&$consumer): void {
            $consumer->stop();
        };
        $consumer->run();

        self::assertSame([[1], self::GRACEFUL_RETURN], $afterLimit);
        self::assertSame($afterLimit, [$this->acked, $this->returnCalls]);
    }

    public function testNoLimitRunsUntilTheQueueEnds(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2'), $this->delivery(3, 'm-3')];

        $this->consumer()->run(new WorkerLimits());

        self::assertSame([1, 2, 3], $this->acked);
        self::assertSame([], $this->logger->records);
    }

    // every acked delivery counts toward the message limit

    public function testADuplicateCountsTowardTheMessageLimit(): void
    {
        $this->dedup->duplicate = true;
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(new WorkerLimits(maxMessages: 1));

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->pending);
    }

    public function testAMalformedDeliveryCountsTowardTheMessageLimit(): void
    {
        $this->pending = [$this->delivery(1, 'm-1', body: '{{{not json'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(new WorkerLimits(maxMessages: 1));

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->pending);
        self::assertCount(1, $this->deadLetterStore->list(new DeadLetterFilter()));
    }

    public function testARetriedDeliveryCountsTowardTheMessageLimit(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(new WorkerLimits(maxMessages: 1));

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

        $this->consumer(maxAttempts: 3)->run(new WorkerLimits(maxMessages: 1));

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

        $this->consumer()->run(new WorkerLimits(maxMessages: 1));

        self::assertSame([1], $this->acked);
        self::assertCount(1, $this->pending);
    }

    // wiring: the processor reaches the channel through the per-delivery control

    public function testARetryReachesTheWaitQueueBeforeTheAck(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [$this->delivery(4, 'm-4')];

        $this->consumer(confirmTimeoutSec: 9)->run(new WorkerLimits(maxMessages: 1));

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

        $consumer->run(new WorkerLimits(maxMessages: 1));

        self::assertSame([5], $this->pendingAckTimeouts);
    }

    public function testWaitQueueIsSetUpOncePerDelayAcrossDeliveries(): void
    {
        $this->failingDispatch = static fn () => throw new RuntimeException('handler failed');
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        $this->consumer()->run(new WorkerLimits(maxMessages: 2));

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
            $this->consumer()->run(new WorkerLimits(maxMessages: 5));
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
            $this->consumer(deserializer: $deserializer)->run(new WorkerLimits(maxMessages: 1));
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
            $this->consumer()->run(new WorkerLimits(maxMessages: 1));
            self::fail('a failed graceful return must end run() with its error');
        } catch (Throwable $e) {
            self::assertSame($nackFailure, $e);
        }

        self::assertSame([1], $this->acked);
        self::assertSame(self::GRACEFUL_RETURN, $this->returnCalls);
        self::assertCount(1, $this->logger->records, 'only the limit stop line, no error');
        self::assertSame(['message limit'], $this->loggedStopReasons());
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
