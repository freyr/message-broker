<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Testing\FakeDeliverySender;
use Freyr\MessageBroker\Testing\InMemoryCircuitBreaker;
use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Testing\InMemoryRateLimiter;
use Freyr\MessageBroker\Testing\InMemorySubscriptionProvider;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Tests\Fixtures\FailingDeliveryStore;
use Freyr\MessageBroker\Tests\Fixtures\StubRateLimiter;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\DefaultDeliveryRetryPolicy;
use Freyr\MessageBroker\Webhook\DeliveryCompletionKind;
use Freyr\MessageBroker\Webhook\DeliveryDecision;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;
use Freyr\MessageBroker\Webhook\DeliveryPair;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryResult;
use Freyr\MessageBroker\Webhook\DeliveryRetryPolicy;
use Freyr\MessageBroker\Webhook\DeliverySender;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\LaneTransitions;
use Freyr\MessageBroker\Webhook\NoCircuitBreaker;
use Freyr\MessageBroker\Webhook\NoRateLimit;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimiter;
use Freyr\MessageBroker\Webhook\Subscription;
use Freyr\MessageBroker\Worker\WorkerLimits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use UnexpectedValueException;

/** The delivery loop with the fakes of the test kit: no database, no HTTP. */
final class DeliveryLoopTest extends TestCase
{
    private const int DAY_MS = 86_400_000;

    private int $sequence = 0;
    private MockClock $clock;
    private int $now;
    private InMemoryDeliveryStore $store;
    private InMemorySubscriptionProvider $subscriptions;
    private FakeDeliverySender $sender;
    private RecordingEvents $events;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($this->clock);
        $this->now = EpochMillis::now();
        $this->store = new InMemoryDeliveryStore();
        $this->subscriptions = new InMemorySubscriptionProvider();
        $this->sender = new FakeDeliverySender();
        $this->events = new RecordingEvents();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testADeliveredResultFinishesTheRowAndRecordsTheEvent(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm', attempts: 2);
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::delivered(37, '200');

        self::assertSame(1, $this->loop()->deliverOnce());

        self::assertSame(DeliveryCompletionKind::Delivered, $this->store->outcome($row->id));
        self::assertSame(3, $this->store->row($row->id)->attempts);
        self::assertSame([[
            'event' => BrokerEvents::WEBHOOK_DELIVERED,
            'context' => [
                'lane' => 'crm',
                'message_id' => $row->messageId,
                'delivery_id' => $row->id,
                'attempts' => 3,
                'request_ms' => 37,
            ],
        ]], $this->events->records);
    }

    public function testEachDeliveryIsSentWithTheSubscriptionOfItsLaneInOneCall(): void
    {
        $crm = $this->subscribe('crm');
        $erp = $this->subscribe('erp');
        $a = $this->insert('crm');
        $b = $this->insert('erp');

        self::assertSame(2, $this->loop()->deliverOnce());

        self::assertCount(1, $this->sender->calls);
        $sent = [];
        foreach ($this->sender->calls[0] as $pair) {
            $sent[$pair->claimed->delivery->id] = $pair->subscription;
        }
        self::assertEqualsCanonicalizing([$a->id, $b->id], array_keys($sent));
        self::assertSame($crm, $sent[$a->id]);
        self::assertSame($erp, $sent[$b->id]);
    }

    public function testAFailedResultAddsOneAttemptAndSetsTheRetryTime(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm', attempts: 1);
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::failed(12, '500 oops');

        self::assertSame(0, $this->loop()->deliverOnce());

        self::assertNull($this->store->outcome($row->id), 'the row is unfinished');
        self::assertSame(2, $this->store->row($row->id)->attempts);
        self::assertSame($this->now + 300_000, $this->store->row($row->id)->availableAt, 'the delay of attempt 2 of the schedule');
        self::assertSame([[
            'event' => BrokerEvents::WEBHOOK_RETRIED,
            'context' => [
                'lane' => 'crm',
                'message_id' => $row->messageId,
                'delivery_id' => $row->id,
                'attempts' => 2,
                'retry_at' => $this->now + 300_000,
            ],
        ]], $this->events->records);
    }

    public function testThePolicyGetsTheNumberOfTheAttemptTheResultAndTheRecord(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm', attempts: 4);
        $result = DeliveryResult::failed(1, '503');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => $result;
        $policy = new class() implements DeliveryRetryPolicy {
            /** @var list<array{int, DeliveryResult, DeliveryRecord}> */
            public array $calls = [];

            public function decide(int $attempt, DeliveryResult $result, DeliveryRecord $delivery): DeliveryDecision
            {
                $this->calls[] = [$attempt, $result, $delivery];

                return DeliveryDecision::retryAt(42);
            }
        };

        $this->loop(policy: $policy)->deliverOnce();

        self::assertCount(1, $policy->calls);
        self::assertSame(5, $policy->calls[0][0]);
        self::assertSame($result, $policy->calls[0][1]);
        self::assertSame($row->id, $policy->calls[0][2]->id);
        self::assertSame(42, $this->store->row($row->id)->availableAt);
    }

    public function testTheEighthFailedResultGivesADeadLetterWithTheSourceWebhookLane(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm', attempts: 7, headers: [
            'correlation_id' => 'c-1',
        ]);
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::failed(12, '500 oops');

        self::assertSame(0, $this->loop()->deliverOnce());

        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($row->id));
        self::assertSame(8, $this->store->row($row->id)->attempts);
        $deadLetter = $this->onlyDeadLetter();
        self::assertSame('webhook:crm', $deadLetter->source);
        self::assertSame($row->messageId, $deadLetter->messageId);
        self::assertSame('order.placed', $deadLetter->messageName);
        self::assertSame($row->body, $deadLetter->body);
        self::assertSame([
            'correlation_id' => 'c-1',
        ], $deadLetter->headers);
        self::assertSame('failed', $deadLetter->errorClass);
        self::assertSame('500 oops', $deadLetter->errorMessage);
        self::assertSame('', $deadLetter->errorTrace);
        self::assertSame(8, $deadLetter->attempts);
        self::assertSame($this->now, $deadLetter->failedAt);
        self::assertTrue($deadLetter->replayable);
        self::assertSame([[
            'event' => BrokerEvents::WEBHOOK_DEAD_LETTERED,
            'context' => [
                'lane' => 'crm',
                'message_id' => $row->messageId,
                'delivery_id' => $row->id,
                'attempts' => 8,
                'reason' => 'failed',
                'reason_code' => null,
            ],
        ]], $this->events->records);
    }

    public function testARefusedResultGivesADeadLetterAtOnceWithTheReasonCode(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::refused('gone', 9, '410');

        $this->loop()->deliverOnce();

        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($row->id));
        $deadLetter = $this->onlyDeadLetter();
        self::assertSame('webhook:crm', $deadLetter->source);
        self::assertSame('refused', $deadLetter->errorClass);
        self::assertSame('gone: 410', $deadLetter->errorMessage);
        self::assertSame(1, $deadLetter->attempts);
        self::assertSame(BrokerEvents::WEBHOOK_DEAD_LETTERED, $this->events->records[0]['event']);
        self::assertSame('refused', $this->events->records[0]['context']['reason']);
        self::assertSame('gone', $this->events->records[0]['context']['reason_code']);
    }

    public function testALateCompleteWithAnOldLeaseChangesNoRowAndRecordsNoEvent(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::refused('gone', 1, '410');
        // The send takes longer than the lease: a second worker claims the row before the result write.
        $this->sender->onSend = function (): void {
            $this->clock->sleep(61);
            $this->store->claim([
                'crm' => 10,
            ], 10, 60_000);
        };

        self::assertSame(0, $this->loop()->deliverOnce());

        self::assertNull($this->store->outcome($row->id));
        self::assertSame(0, $this->store->row($row->id)->attempts);
        self::assertSame(0, $this->store->deadLetters()->count(new DeadLetterFilter()));
        self::assertSame([], $this->events->records);
    }

    public function testARowThatTheOperatorAbandonsDuringTheSendStaysFinishedWhenTheWorkerWritesItsResult(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm', attempts: 2);
        // The operator abandons the leased row while the request runs.
        $this->sender->onSend = function (): void {
            self::assertSame(1, $this->store->abandon($this->now, null, 10));
        };

        self::assertSame(0, $this->loop()->deliverOnce());

        self::assertSame([$row->id], $this->sender->sentIds(), 'the worker still sends its request');
        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($row->id));
        self::assertSame(2, $this->store->row($row->id)->attempts, 'the result write changed no row');
        self::assertSame('abandoned', $this->onlyDeadLetter()->errorClass);
        self::assertSame([], $this->events->records, 'no delivered event for a row that the store did not change');
    }

    public function testOnlyTheRowsThatTheStoreChangedCountAsDelivered(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $late = $this->insert('crm');
        $this->sender->onSend = function () use ($late): void {
            // The lease of one row ended and a second worker claimed it.
            $this->store->insert([new DeliveryRecord($late->id, $late->messageId, 'crm', 'order.placed', '{}', [], $late->createdAt, 0, $this->now + 1)]);
        };

        self::assertSame(1, $this->loop()->deliverOnce());

        self::assertNull($this->store->outcome($late->id));
        self::assertCount(1, $this->events->records);
    }

    public function testARowOfThirtyDaysAgoStaysDueAndIsDelivered(): void
    {
        $this->subscribe('crm');
        $old = $this->insert('crm', attempts: 3, createdAt: $this->now - 30 * self::DAY_MS);

        self::assertSame(1, $this->loop()->deliverOnce());

        self::assertSame([$old->id], $this->sender->sentIds(), 'a delivery has no time limit');
        self::assertSame(DeliveryCompletionKind::Delivered, $this->store->outcome($old->id));
        self::assertSame(4, $this->store->row($old->id)->attempts);
        self::assertSame(0, $this->store->deadLetters()->count(new DeadLetterFilter()));
        self::assertSame([BrokerEvents::WEBHOOK_DELIVERED], array_column($this->events->records, 'event'));
    }

    public function testAnUnknownLaneSendsNoRequestAndUsesNoAttempt(): void
    {
        $this->subscribe('crm');
        $this->subscribe('erp');
        $loop = $this->loop();
        $loop->deliverOnce(); // reads the lanes: crm, erp
        $this->subscriptions->remove('erp');
        $known = $this->insert('crm');
        $unknown = $this->insert('erp');

        self::assertSame(1, $loop->deliverOnce());

        self::assertSame([$known->id], $this->sender->sentIds());
        self::assertNull($this->store->outcome($unknown->id));
        self::assertSame(0, $this->store->row($unknown->id)->attempts);
        self::assertSame($this->now + 60_000, $this->store->row($unknown->id)->availableAt, 'the row waits for its subscription or an abandon');
    }

    public function testAClaimOfOnlyUnknownLanesSendsNothing(): void
    {
        $this->subscribe('erp');
        $loop = $this->loop();
        $loop->deliverOnce();
        $this->subscriptions->remove('erp');
        $this->insert('erp');

        self::assertSame(0, $loop->deliverOnce());

        self::assertSame([], $this->sender->calls);
    }

    public function testTheSubscriptionOfALaneIsReadOneTimeForEachPass(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $this->insert('crm');
        $this->insert('crm');

        $this->loop()->deliverOnce();

        self::assertSame(['crm'], $this->subscriptions->finds);
    }

    public function testTheClaimUsesTheLanesTheLimitsAndTheLeaseOfTheConfiguration(): void
    {
        $this->subscribe('crm');

        $this->loop(new DeliveryLoopConfig(leaseMs: 45_000, batchSize: 70, limitForEachLane: 7, idleSleepMs: 1))->deliverOnce();

        self::assertSame([[
            'limitForEachLane' => [
                'crm' => 7,
            ],
            'total' => 70,
            'leaseMs' => 45_000,
        ]], $this->store->claims);
    }

    public function testNoLaneClaimsNothing(): void
    {
        self::assertSame(0, $this->loop()->deliverOnce());

        self::assertSame(0, $this->store->claimCalls);
    }

    public function testTheLanesAreReadAgainAtTheRefreshInterval(): void
    {
        $this->subscribe('crm');
        $loop = $this->loop(new DeliveryLoopConfig(laneRefreshMs: 30_000, idleSleepMs: 1));
        $loop->deliverOnce();
        $this->subscribe('erp');

        $this->clock->sleep(29.999);
        $loop->deliverOnce();
        $this->clock->sleep(0.001);
        $loop->deliverOnce();

        self::assertSame(2, $this->subscriptions->lanesCalls);
        self::assertSame(['crm'], self::lanesOf($this->store->claims[1]), 'the old list before the interval');
        self::assertEqualsCanonicalizing(['crm', 'erp'], self::lanesOf($this->store->claims[2]));
    }

    public function testTheLaneFilterKeepsOnlyTheLanesOfTheFilter(): void
    {
        foreach (['crm', 'erp', 'bi'] as $lane) {
            $this->subscribe($lane);
        }

        $this->loop(new DeliveryLoopConfig(laneFilter: ['bi', 'crm', 'unknown'], idleSleepMs: 1))->deliverOnce();

        self::assertEqualsCanonicalizing(['crm', 'bi'], self::lanesOf($this->store->claims[0]));
    }

    public function testTheLanesOfEachClaimAreInARandomOrder(): void
    {
        $lanes = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
        foreach ($lanes as $lane) {
            $this->subscribe($lane);
        }
        $expected = new Randomizer(new Mt19937(7));
        $loop = $this->loop(randomizer: new Randomizer(new Mt19937(7)));

        $loop->deliverOnce();
        $loop->deliverOnce();

        $first = $expected->shuffleArray($lanes);
        $second = $expected->shuffleArray($lanes);
        self::assertNotSame($first, $second, 'the seed gives two different orders');
        self::assertSame($first, self::lanesOf($this->store->claims[0]));
        self::assertSame($second, self::lanesOf($this->store->claims[1]));
    }

    public function testASenderWithNoResultForADeliveryStopsTheLoop(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm');
        $sender = new class() implements DeliverySender {
            public function send(array $pairs): array
            {
                return [];
            }

            public function maxSendMs(): int
            {
                return 1;
            }
        };
        $loop = new DeliveryLoop($this->store, $sender, $this->subscriptions);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("The sender returned no result for the delivery {$row->id}");

        $loop->deliverOnce();
    }

    public function testADatabaseErrorInTheResultWriteStopsTheRunAndTheRowStaysLeased(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm');
        $store = new FailingDeliveryStore($this->store);
        $store->completeError = new RuntimeException('connection lost');

        try {
            $this->loop(store: $store)->run();
            self::fail('expected the error');
        } catch (RuntimeException $e) {
            self::assertSame('connection lost', $e->getMessage());
        }

        self::assertNull($this->store->outcome($row->id));
        self::assertSame($this->now + 60_000, $this->store->row($row->id)->availableAt, 'due again after the lease');
    }

    public function testADatabaseErrorInTheClaimStopsTheRun(): void
    {
        $this->subscribe('crm');
        $store = new FailingDeliveryStore($this->store);
        $store->claimError = new RuntimeException('server closed the connection');

        $this->expectExceptionMessage('server closed the connection');

        $this->loop(store: $store)->run();
    }

    public function testASenderErrorStopsTheRun(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $this->sender->onSend = static fn () => throw new RuntimeException('bad sender configuration');

        $this->expectExceptionMessage('bad sender configuration');

        $this->loop()->run();
    }

    public function testTheRunRefusesALeaseThatIsNotLongerThanTheLongestSendCall(): void
    {
        $this->subscribe('crm');
        $this->sender = new FakeDeliverySender(maxSendMs: 60_000);
        $loop = $this->loop(new DeliveryLoopConfig(leaseMs: 60_000, idleSleepMs: 1));
        $this->store->onClaim = static fn () => $loop->stop(); // a loop that starts ends after one pass

        try {
            $loop->run();
            self::fail('expected the refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('The lease (60000 ms) must be longer than the longest send call of the sender (60000 ms)', $e->getMessage());
        }

        self::assertSame(0, $this->store->claimCalls, 'the loop does not start');
    }

    public function testTheRunAcceptsALeaseOneMillisecondLongerThanTheLongestSendCall(): void
    {
        $this->subscribe('crm');
        $this->sender = new FakeDeliverySender(maxSendMs: 59_999);
        $loop = $this->loop(new DeliveryLoopConfig(leaseMs: 60_000, idleSleepMs: 1));
        $this->store->onClaim = static fn () => $loop->stop();

        $loop->run();

        self::assertSame(1, $this->store->claimCalls);
    }

    public function testTheDefaultConfigurationPassesTheStartChecksWithTheDefaultPolicy(): void
    {
        $this->subscribe('crm');
        $loop = new DeliveryLoop($this->store, $this->sender, $this->subscriptions);
        $this->store->onClaim = static fn () => $loop->stop();

        $loop->run();

        self::assertSame(1, $this->store->claimCalls);
    }

    public function testAMessageLimitStopsTheRunAtTheEndOfThePassThatReachesIt(): void
    {
        $this->subscribe('crm');
        foreach (range(1, 25) as $ignored) {
            $this->insert('crm');
        }

        $this->loop(new DeliveryLoopConfig(batchSize: 10, limitForEachLane: 10, idleSleepMs: 1))->run(new WorkerLimits(maxMessages: 15));

        self::assertSame(2, $this->store->claimCalls, 'the second pass reaches 20, above the limit of 15');
        self::assertCount(20, $this->sender->sentIds());
    }

    public function testFailedResultsDoNotCountForTheMessageLimit(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::failed(1, '500');
        $loop = $this->loop();
        $this->store->onClaim = static function (int $call) use ($loop): void {
            if ($call === 3) {
                $loop->stop();
            }
        };

        $loop->run(new WorkerLimits(maxMessages: 1));

        self::assertSame(3, $this->store->claimCalls);
    }

    public function testALimitStopLogsTheReasonTheCountsTheElapsedTimeAndTheMemory(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $this->insert('crm');
        $this->store->onClaim = function (): void {
            $this->clock->sleep(2);
        };

        $this->loop()->run(new WorkerLimits(maxMessages: 2));

        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::INFO, $record['level']);
        self::assertSame('Worker limit reached; the worker stops', $record['message']);
        self::assertSame('message limit', $record['context']['reason']);
        self::assertSame(2, $record['context']['messages']);
        self::assertSame(0, $record['context']['failures']);
        self::assertSame(2.0, $record['context']['elapsedSeconds']);
        self::assertIsInt($record['context']['memoryBytes']);
        self::assertGreaterThan(0, $record['context']['memoryBytes']);
    }

    public function testATimeLimitStopsAnIdleRun(): void
    {
        $this->subscribe('crm');
        $this->store->onClaim = function (): void {
            $this->clock->sleep(4);
        };

        $this->loop()->run(new WorkerLimits(maxSeconds: 10));

        self::assertSame(3, $this->store->claimCalls, 'the clock shows 4 s, 8 s, then 12 s');
    }

    public function testARunWithNoLimitsRunsUntilStopAndAStoppedLoopDoesNotStartAgain(): void
    {
        $this->subscribe('crm');
        $loop = $this->loop();
        $this->store->onClaim = static function (int $call) use ($loop): void {
            if ($call === 3) {
                $loop->stop();
            }
        };

        $loop->run();
        $loop->run();

        self::assertSame(3, $this->store->claimCalls);
        self::assertSame([], $this->logger->records);
    }

    public function testAnIdlePassSleepsAndAProductivePassDoesNot(): void
    {
        Clock::set(new NativeClock());
        $this->subscribe('crm');
        $loop = $this->loop(new DeliveryLoopConfig(idleSleepMs: 20));
        $this->store->onClaim = static function (int $call) use ($loop): void {
            if ($call === 2) {
                $loop->stop();
            }
        };

        $start = hrtime(true);
        $loop->run();

        self::assertGreaterThanOrEqual(20.0, (hrtime(true) - $start) / 1e6, 'the idle pass sleeps at least the idle interval');

        $this->insert('crm', createdAt: EpochMillis::now());
        $busy = $this->loop(new DeliveryLoopConfig(idleSleepMs: 2_000));
        $this->sender->onSend = static fn () => $busy->stop();
        $start = hrtime(true);
        $busy->run();
        self::assertLessThan(1_000.0, (hrtime(true) - $start) / 1e6, 'a productive pass does not sleep');
    }

    /** @return iterable<string, array{int}> */
    public static function stopSignals(): iterable
    {
        yield 'SIGTERM' => [SIGTERM];
        yield 'SIGINT' => [SIGINT];
    }

    #[DataProvider('stopSignals')]
    public function testASignalStopsTheRunAtTheEndOfThePass(int $signal): void
    {
        $this->subscribe('crm');
        $loop = $this->loop();
        // A handler that ignores the signal, and synchronous signals: the run must set both up itself.
        pcntl_signal($signal, static function (): void {});
        pcntl_async_signals(false);
        $this->store->onClaim = static function (int $call) use ($loop, $signal): void {
            if ($call === 1) {
                posix_kill(getmypid(), $signal);
            }
            if ($call === 5) {
                $loop->stop(); // the bound of a run that ignores the signal
            }
        };

        try {
            $loop->run();
        } finally {
            pcntl_signal($signal, SIG_DFL);
        }

        self::assertSame(1, $this->store->claimCalls);
    }

    public function testALoopWithNoEventListenerDelivers(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm');
        $loop = new DeliveryLoop($this->store, $this->sender, $this->subscriptions, config: new DeliveryLoopConfig(idleSleepMs: 1));

        self::assertSame(1, $loop->deliverOnce());

        self::assertSame(DeliveryCompletionKind::Delivered, $this->store->outcome($row->id));
    }

    public function testWithNoRateLimitTheLoopReadsNoDueCountAndClaimsTheRowLimitOfEachLaneAndTheBatchSize(): void
    {
        $this->subscribe('crm');
        $this->subscribe('erp');

        $this->loop()->deliverOnce();

        self::assertSame([], $this->store->dueCountCalls);
        self::assertSame([
            'crm' => 25,
            'erp' => 25,
        ], self::sortedLimits($this->store->claims[0]));
        self::assertSame(100, $this->store->claims[0]['total']);
        self::assertSame([], $this->subscriptions->finds, 'no read of the lane rates');
    }

    public function testOpenLanesAreNotInTheDueCountsTheAcquireOrTheClaim(): void
    {
        $lanes = ['crm', 'erp', 'bi'];
        foreach ($lanes as $lane) {
            $this->subscribe($lane);
            $this->insert($lane);
        }
        $order = (new Randomizer(new Mt19937(7)))->shuffleArray($lanes);
        $breaker = new InMemoryCircuitBreaker();
        // The first lane of the pass is open: the lanes left keep their order as a list.
        $breaker->record([new LaneCounts($order[0], 10, 10)]);
        $limiter = new StubRateLimiter();

        self::assertSame(2, $this->loop(randomizer: new Randomizer(new Mt19937(7)), rateLimiter: $limiter, circuitBreaker: $breaker)->deliverOnce());

        $inCirculation = array_slice($order, 1);
        self::assertSame([[
            'lanes' => $inCirculation,
            'cap' => 25,
        ]], $this->store->dueCountCalls);
        self::assertTrue(array_is_list($this->store->dueCountCalls[0]['lanes']));
        self::assertSame(array_fill_keys($inCirculation, 1), $limiter->acquires[0]['wanted']);
        self::assertSame(array_fill_keys($inCirculation, 1), $this->store->claims[0]['limitForEachLane']);
        self::assertSame(1, $breaker->openLanesCalls);
    }

    public function testTheDueCountsCapAtTheRowLimitAndALaneWithNoDueRowIsNotInTheAcquire(): void
    {
        foreach (['crm', 'erp', 'bi'] as $lane) {
            $this->subscribe($lane);
        }
        foreach (range(1, 30) as $ignored) {
            $this->insert('crm');
        }
        $this->insert('bi');
        $this->insert('bi');
        $limiter = new StubRateLimiter();

        $this->loop(rateLimiter: $limiter)->deliverOnce();

        self::assertSame(25, $this->store->dueCountCalls[0]['cap'], 'the row limit for each lane');
        self::assertEqualsCanonicalizing(['crm', 'erp', 'bi'], $this->store->dueCountCalls[0]['lanes']);
        $wanted = $limiter->acquires[0]['wanted'];
        ksort($wanted);
        self::assertSame([
            'bi' => 2,
            'crm' => 25,
        ], $wanted);
        self::assertSame(100, $limiter->acquires[0]['total'], 'the batch size');
    }

    public function testNoDueRowInAnyLaneAcquiresNothingAndClaimsNothing(): void
    {
        $this->subscribe('crm');
        $this->insert('crm', availableAt: $this->now + 60_000);
        $limiter = new StubRateLimiter();

        self::assertSame(0, $this->loop(rateLimiter: $limiter)->deliverOnce());

        self::assertCount(1, $this->store->dueCountCalls);
        self::assertSame([], $limiter->acquires);
        self::assertSame(0, $this->store->claimCalls);
        self::assertSame([], $this->flowEvents(), 'no wait for permits');
    }

    public function testTheAcquireAndTheClaimGetTheDueLanesInTheRandomOrderOfThePass(): void
    {
        $lanes = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
        foreach ($lanes as $lane) {
            $this->subscribe($lane);
            $this->insert($lane);
        }
        $limiter = new StubRateLimiter();

        $this->loop(randomizer: new Randomizer(new Mt19937(7)), rateLimiter: $limiter)->deliverOnce();

        $order = (new Randomizer(new Mt19937(7)))->shuffleArray($lanes);
        self::assertNotSame($lanes, $order);
        self::assertSame($order, array_keys($limiter->acquires[0]['wanted']));
        self::assertSame($order, self::lanesOf($this->store->claims[0]));
    }

    public function testTheClaimLimitOfALaneIsItsGrantAndTheTotalIsTheSumOfTheGrants(): void
    {
        $this->subscribe('crm');
        $this->subscribe('bi');
        foreach (range(1, 5) as $ignored) {
            $this->insert('crm');
        }
        $this->insert('bi');
        $this->insert('bi');
        $limiter = new InMemoryRateLimiter();

        $delivered = $this->loop(new DeliveryLoopConfig(idleSleepMs: 1, defaultLaneRate: new Rate(1.0, 3)), rateLimiter: $limiter)->deliverOnce();

        self::assertSame(5, $delivered);
        self::assertSame([
            'bi' => 2,
            'crm' => 3,
        ], self::sortedLimits($this->store->claims[0]));
        self::assertSame(5, $this->store->claims[0]['total']);
        self::assertSame([], $limiter->givenBack, 'the claim used each permit');
    }

    public function testTheClaimLimitIsTheRowLimitWhenTheGrantIsLarger(): void
    {
        $this->subscribe('crm');
        foreach (range(1, 30) as $ignored) {
            $this->insert('crm');
        }
        $limiter = new StubRateLimiter(static fn (array $wanted): array => array_map(static fn (int $count): int => $count + 10, $wanted));

        $this->loop(rateLimiter: $limiter)->deliverOnce();

        self::assertSame([
            'crm' => 25,
        ], $this->store->claims[0]['limitForEachLane']);
        self::assertSame(35, $this->store->claims[0]['total']);
        self::assertSame([
            'crm' => 10,
        ], $limiter->givenBack[0]['unused']);
    }

    public function testALaneWithAGrantOfZeroIsNotInTheClaimAndGetsTheLaneAtLimitEvent(): void
    {
        $this->subscribe('crm');
        $this->subscribe('erp');
        $this->insert('crm');
        $this->insert('erp');
        $limiter = new StubRateLimiter(static fn (array $wanted): array => [
            'erp' => 0,
        ] + $wanted);

        self::assertSame(1, $this->loop(rateLimiter: $limiter)->deliverOnce());

        self::assertSame([
            'crm' => 1,
        ], $this->store->claims[0]['limitForEachLane']);
        self::assertSame(1, $this->store->claims[0]['total']);
        self::assertSame([[
            'lane' => 'erp',
            'wanted' => 1,
            'granted' => 0,
        ]], $this->contextsOf(BrokerEvents::WEBHOOK_LANE_AT_LIMIT));
        self::assertSame([], $this->contextsOf(BrokerEvents::WEBHOOK_PERMIT_WAIT));
    }

    public function testOnlyALaneWithADifferenceAboveZeroGivesPermitsBack(): void
    {
        $this->subscribe('crm');
        $this->subscribe('erp');
        $this->insert('crm');
        $this->insert('crm');
        $this->insert('erp');
        $limiter = new StubRateLimiter(static fn (array $wanted): array => [
            'erp' => 3,
        ] + $wanted);
        $config = new DeliveryLoopConfig(idleSleepMs: 1, globalRate: new Rate(500.0, 100));

        $this->loop($config, rateLimiter: $limiter)->deliverOnce();

        self::assertCount(1, $limiter->givenBack);
        self::assertSame([
            'erp' => 2,
        ], $limiter->givenBack[0]['unused'], 'crm claimed its two rows; erp claimed one of three');
        self::assertSame($limiter->acquires[0]['limits'], $limiter->givenBack[0]['limits'], 'the rates of the pass');
    }

    public function testASumOfGrantsOfZeroGivesNoClaimAndOneWaitEventWithTheLanes(): void
    {
        $this->subscribe('crm');
        $this->subscribe('erp');
        $this->insert('crm');
        $this->insert('erp');
        $this->insert('erp');
        $limiter = new StubRateLimiter(static fn (array $wanted): array => array_map(static fn (): int => 0, $wanted));
        $config = new DeliveryLoopConfig(idleSleepMs: 1, permitWaitMs: 40);
        $start = (int) $this->clock->now()->format('Uu');

        $passed = $this->loop($config, randomizer: new Randomizer(new Mt19937(7)), rateLimiter: $limiter)->deliverOnce();

        $order = (new Randomizer(new Mt19937(7)))->shuffleArray(['crm', 'erp']);
        self::assertSame(0, $passed);
        self::assertSame(0, $this->store->claimCalls);
        self::assertSame([], $this->sender->calls);
        self::assertSame([], $limiter->givenBack);
        self::assertSame(
            $start + 40_000,
            (int) $this->clock->now()->format('Uu'),
            'the loop sleeps exactly the permit wait through the clock, in microseconds',
        );
        self::assertSame([[
            'lanes' => $order,
            'sleep_ms' => 40,
        ]], $this->contextsOf(BrokerEvents::WEBHOOK_PERMIT_WAIT));
        self::assertSame([
            BrokerEvents::WEBHOOK_LANE_AT_LIMIT,
            BrokerEvents::WEBHOOK_LANE_AT_LIMIT,
            BrokerEvents::WEBHOOK_PERMIT_WAIT,
        ], $this->flowEvents(), 'each lane is at its limit, then the one wait');
    }

    public function testAGrantBelowTheWantedCountGivesOneLaneAtLimitEvent(): void
    {
        $this->subscribe('crm');
        $this->subscribe('bi');
        foreach (range(1, 5) as $ignored) {
            $this->insert('crm');
        }
        $this->insert('bi');
        $this->insert('bi');

        $this->loop(new DeliveryLoopConfig(idleSleepMs: 1, defaultLaneRate: new Rate(1.0, 3)), rateLimiter: new InMemoryRateLimiter())->deliverOnce();

        self::assertSame([[
            'lane' => 'crm',
            'wanted' => 5,
            'granted' => 3,
        ]], $this->contextsOf(BrokerEvents::WEBHOOK_LANE_AT_LIMIT), 'bi got its wanted count');
    }

    public function testTheEventsOfANumericLaneHaveTheLaneAsAString(): void
    {
        $this->subscribe('42');
        $this->insert('42');
        $this->insert('42');
        $grants = [1, 0];
        $limiter = new StubRateLimiter(static function (array $wanted) use (&$grants): array {
            $grant = (int) array_shift($grants);

            return array_map(static fn (): int => $grant, $wanted);
        });
        $loop = $this->loop(rateLimiter: $limiter);

        $loop->deliverOnce();
        $loop->deliverOnce();

        self::assertSame([[
            'lane' => '42',
            'wanted' => 2,
            'granted' => 1,
        ], [
            'lane' => '42',
            'wanted' => 1,
            'granted' => 0,
        ]], $this->contextsOf(BrokerEvents::WEBHOOK_LANE_AT_LIMIT));
        self::assertSame([[
            'lanes' => ['42'],
            'sleep_ms' => 50,
        ]], $this->contextsOf(BrokerEvents::WEBHOOK_PERMIT_WAIT));
    }

    public function testTheLaneRateIsTheRateOfTheSubscriptionThenTheDefaultLaneRate(): void
    {
        $own = new Rate(5.0, 2);
        $default = new Rate(40.0);
        $global = new Rate(1_000.0, 100);
        $this->subscribe('crm', $own);
        $this->subscribe('erp');
        $this->insert('crm');
        $limiter = new StubRateLimiter();

        $this->loop(new DeliveryLoopConfig(idleSleepMs: 1, globalRate: $global, defaultLaneRate: $default), rateLimiter: $limiter)->deliverOnce();

        $limits = $limiter->acquires[0]['limits'];
        self::assertSame($global, $limits->global);
        self::assertSame($own, $limits->forLane('crm'));
        self::assertSame($default, $limits->forLane('erp'));
        self::assertEqualsCanonicalizing(['crm', 'erp', 'crm'], $this->subscriptions->finds, 'one read of each lane at the refresh, and one for the send');
    }

    public function testALaneWithNoRateAndNoDefaultLaneRateHasNoLaneLimit(): void
    {
        $own = new Rate(5.0, 2);
        $this->subscribe('crm', $own);
        $this->subscribe('erp');
        $this->insert('crm');
        $limiter = new StubRateLimiter();

        $this->loop(rateLimiter: $limiter)->deliverOnce();

        $limits = $limiter->acquires[0]['limits'];
        self::assertSame([
            'crm' => $own,
        ], $limits->lanes);
        self::assertNull($limits->global, 'a null global rate: no global bucket');
    }

    public function testAChangedRateOfASubscriptionHasEffectAfterOneRefresh(): void
    {
        $old = new Rate(5.0, 2);
        $new = new Rate(10.0, 3);
        $this->subscribe('crm', $old);
        $limiter = new StubRateLimiter();
        $loop = $this->loop(new DeliveryLoopConfig(laneRefreshMs: 30_000, idleSleepMs: 1), rateLimiter: $limiter);
        $this->insert('crm');
        $loop->deliverOnce();
        $this->subscriptions->remove('crm');
        $this->subscribe('crm', $new);

        $this->clock->sleep(29.999);
        $this->insert('crm');
        $loop->deliverOnce();
        $this->clock->sleep(0.001);
        $this->insert('crm');
        $loop->deliverOnce();

        self::assertCount(3, $limiter->acquires);
        self::assertSame($old, $limiter->acquires[0]['limits']->forLane('crm'));
        self::assertSame($old, $limiter->acquires[1]['limits']->forLane('crm'), 'the old rate before the interval');
        self::assertSame($new, $limiter->acquires[2]['limits']->forLane('crm'));
    }

    public function testANullGlobalRateHasNoStartCheckOfTheBatchSize(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $limiter = new StubRateLimiter();
        $loop = $this->loop(new DeliveryLoopConfig(batchSize: 10_000, idleSleepMs: 1), rateLimiter: $limiter);
        $this->store->onClaim = static fn () => $loop->stop();

        $loop->run();

        self::assertSame(1, $this->store->claimCalls);
        self::assertNull($limiter->acquires[0]['limits']->global);
        self::assertSame(10_000, $limiter->acquires[0]['total']);
    }

    public function testTheRunRefusesABatchSizeLargerThanTheBurstCapacityOfTheGlobalRate(): void
    {
        $this->subscribe('crm');
        $loop = $this->loop(new DeliveryLoopConfig(batchSize: 101, idleSleepMs: 1, globalRate: new Rate(1_000.0, 100)));
        $this->store->onClaim = static fn () => $loop->stop();

        try {
            $loop->run();
            self::fail('expected the refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('The batch size (101) must not be larger than the burst capacity of the global rate (100)', $e->getMessage());
        }

        self::assertSame(0, $this->store->claimCalls, 'the loop does not start');
    }

    public function testTheRunAcceptsABatchSizeEqualToTheBurstCapacityOfTheGlobalRate(): void
    {
        $this->subscribe('crm');
        $loop = $this->loop(new DeliveryLoopConfig(batchSize: 100, idleSleepMs: 1, globalRate: new Rate(1_000.0, 100)));
        $this->store->onClaim = static fn () => $loop->stop();

        $loop->run();

        self::assertSame(1, $this->store->claimCalls);
    }

    public function testAPassThatWaitedForPermitsDoesNotAlsoSleepTheIdleSleep(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $loop = null;
        $calls = 0;
        $limiter = new StubRateLimiter(static function (array $wanted) use (&$loop, &$calls): array {
            if (++$calls === 2) {
                $loop?->stop();
            }

            return array_map(static fn (): int => 0, $wanted);
        });
        $loop = $this->loop(new DeliveryLoopConfig(idleSleepMs: 2_000, permitWaitMs: 1), rateLimiter: $limiter);

        $start = hrtime(true);
        $loop->run();

        self::assertLessThan(1_000.0, (hrtime(true) - $start) / 1e6, 'no idle sleep of 2 s');
        self::assertSame($this->now + 2, EpochMillis::now(), 'two permit waits of 1 ms');
        self::assertCount(2, $this->contextsOf(BrokerEvents::WEBHOOK_PERMIT_WAIT));
    }

    public function testAnIdlePassAfterAPermitWaitSleepsTheIdleSleep(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::failed(1, '500');
        $calls = 0;
        // The first pass waits for permits; the second gets its grant and delivers nothing.
        $limiter = new StubRateLimiter(static function (array $wanted) use (&$calls): array {
            return ++$calls === 1 ? array_map(static fn (): int => 0, $wanted) : $wanted;
        });
        $loop = $this->loop(new DeliveryLoopConfig(idleSleepMs: 20, permitWaitMs: 1), rateLimiter: $limiter);
        $this->sender->onSend = static fn () => $loop->stop();

        $start = hrtime(true);
        $loop->run();

        self::assertSame(1, $this->store->claimCalls);
        self::assertGreaterThanOrEqual(20.0, (hrtime(true) - $start) / 1e6, 'the idle pass sleeps at least the idle interval');
    }

    public function testALaneOutOfCirculationIsNotInTheClaim(): void
    {
        $this->subscribe('crm');
        $this->subscribe('erp');
        $breaker = new InMemoryCircuitBreaker();
        $breaker->record([new LaneCounts('erp', 10, 10)]);

        $this->loop(circuitBreaker: $breaker)->deliverOnce();

        self::assertSame(['crm'], self::lanesOf($this->store->claims[0]));
        self::assertSame(1, $breaker->openLanesCalls);
    }

    public function testTheLanesInCirculationKeepTheirRandomOrder(): void
    {
        $lanes = ['a', 'b', 'c', 'd', 'e'];
        foreach ($lanes as $lane) {
            $this->subscribe($lane);
        }
        $order = (new Randomizer(new Mt19937(7)))->shuffleArray($lanes);
        $breaker = new InMemoryCircuitBreaker();
        $breaker->record([new LaneCounts($order[0], 10, 10)]); // the first lane of the claim is open

        $this->loop(randomizer: new Randomizer(new Mt19937(7)), circuitBreaker: $breaker)->deliverOnce();

        self::assertSame(array_slice($order, 1), self::lanesOf($this->store->claims[0]));
    }

    public function testOnlyLanesOutOfCirculationReadNoDueCountTakeNoPermitAndClaimNothing(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $breaker = new InMemoryCircuitBreaker();
        $breaker->record([new LaneCounts('crm', 10, 10)]);
        $limiter = new StubRateLimiter();

        self::assertSame(0, $this->loop(rateLimiter: $limiter, circuitBreaker: $breaker)->deliverOnce());

        self::assertSame([], $this->store->dueCountCalls);
        self::assertSame([], $limiter->acquires);
        self::assertSame(0, $this->store->claimCalls);
    }

    public function testNoLaneReadsNoOpenLane(): void
    {
        $breaker = new InMemoryCircuitBreaker();

        $this->loop(circuitBreaker: $breaker)->deliverOnce();

        self::assertSame(0, $breaker->openLanesCalls);
    }

    public function testTheCountsOfOneBatchGoToTheCircuitBreakerInOneCall(): void
    {
        $this->subscribe('crm');
        $this->subscribe('erp');
        $failed = $this->insert('crm');
        $this->insert('crm');
        $this->insert('crm');
        $refused = $this->insert('erp');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => match ($p->claimed->delivery->id) {
            $failed->id => DeliveryResult::failed(1, '500'),
            $refused->id => DeliveryResult::refused('gone', 1, '410'),
            default => DeliveryResult::delivered(1),
        };
        $breaker = new InMemoryCircuitBreaker();

        $this->loop(circuitBreaker: $breaker)->deliverOnce();

        self::assertCount(1, $breaker->records);
        self::assertSame([
            'crm' => [3, 1],
            'erp' => [1, 1],
        ], self::countsByLane($breaker->records[0]));
    }

    public function testTheCountsOfANumericLaneKeepTheLaneAsAString(): void
    {
        $this->subscribe('42');
        $this->insert('42');
        $breaker = new InMemoryCircuitBreaker();

        $this->loop(circuitBreaker: $breaker)->deliverOnce();

        self::assertCount(1, $breaker->records[0]);
        self::assertSame('42', $breaker->records[0][0]->lane);
    }

    public function testAPassWithNoSentRequestRecordsNothing(): void
    {
        $this->subscribe('crm');
        $breaker = new InMemoryCircuitBreaker();

        $this->loop(circuitBreaker: $breaker)->deliverOnce();

        self::assertSame([], $breaker->records);
    }

    public function testADeliveredResultAboveTheSlowLimitOfThePolicyOfTheBreakerCountsAsAFailureAndItsRowIsDelivered(): void
    {
        $this->subscribe('crm');
        $slow = $this->insert('crm');
        $atTheLimit = $this->insert('crm');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::delivered(
            $p->claimed->delivery->id === $slow->id ? 101 : 100,
        );
        // Not the default slow limit of 5 s: the loop reads the slow limit of the breaker.
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(slowLimitMs: 100));

        self::assertSame(2, $this->loop(circuitBreaker: $breaker)->deliverOnce());

        self::assertSame([
            'crm' => [2, 1],
        ], self::countsByLane($breaker->records[0]));
        self::assertSame(DeliveryCompletionKind::Delivered, $this->store->outcome($slow->id));
        self::assertSame(DeliveryCompletionKind::Delivered, $this->store->outcome($atTheLimit->id));
        self::assertSame(1, $this->store->row($slow->id)->attempts, 'no retry');
    }

    public function testASlowLimitOfZeroCountsNoSlowResult(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::delivered(3_600_000);
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(slowLimitMs: 0));

        $this->loop(circuitBreaker: $breaker)->deliverOnce();

        self::assertSame([
            'crm' => [1, 0],
        ], self::countsByLane($breaker->records[0]));
    }

    public function testTheDeliveriesOfALaneOutOfCirculationKeepTheirAttemptsAndRetryTime(): void
    {
        $this->subscribe('crm');
        $a = $this->insert('crm');
        $b = $this->insert('crm');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::failed(1, '500');
        $loop = $this->loop(circuitBreaker: new InMemoryCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 2, errorRatePercent: 100, openMs: 60_000)));
        $loop->deliverOnce(); // two failures: the lane trips
        $new = $this->insert('crm');

        $this->clock->sleep(30); // the retry time (5 s) passed; the open time did not
        self::assertSame(0, $loop->deliverOnce());

        self::assertSame(1, $this->store->claimCalls);
        self::assertCount(1, $this->sender->calls);
        foreach ([$a, $b] as $row) {
            self::assertSame(1, $this->store->row($row->id)->attempts);
            self::assertSame($this->now + 5_000, $this->store->row($row->id)->availableAt);
        }
        self::assertSame(0, $this->store->row($new->id)->attempts);
    }

    public function testOneTripGivesOneLeftEventAndOneReturnedEvent(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $this->insert('crm');
        $failing = true;
        $this->sender->respond = static function (DeliveryPair $p) use (&$failing): DeliveryResult {
            return $failing ? DeliveryResult::failed(1, '500') : DeliveryResult::delivered(1);
        };
        $loop = $this->loop(circuitBreaker: new InMemoryCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 2, errorRatePercent: 100, openMs: 60_000)));

        $loop->deliverOnce();
        self::assertSame([BrokerEvents::WEBHOOK_LANE_OPENED], $this->flowEvents());

        $this->clock->sleep(60);
        $failing = false;
        self::assertSame(2, $loop->deliverOnce(), 'the lane is back in the claim');
        $this->insert('crm');
        self::assertSame(1, $loop->deliverOnce());

        self::assertSame([BrokerEvents::WEBHOOK_LANE_OPENED, BrokerEvents::WEBHOOK_LANE_RETURNED], $this->flowEvents());
        self::assertSame([[
            'lane' => 'crm',
        ]], $this->contextsOf(BrokerEvents::WEBHOOK_LANE_OPENED));
        self::assertSame([[
            'lane' => 'crm',
        ]], $this->contextsOf(BrokerEvents::WEBHOOK_LANE_RETURNED));
    }

    public function testTheFourthTripInARowGivesOneBlockedEventAndNoLeftEvent(): void
    {
        $this->subscribe('crm');
        $this->sender->respond = static fn (DeliveryPair $p): DeliveryResult => DeliveryResult::failed(1, '500');
        $loop = $this->loop(circuitBreaker: new InMemoryCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 1, errorRatePercent: 100, openMs: 60_000)));

        foreach (range(1, 4) as $trip) {
            $this->insert('crm');
            $loop->deliverOnce();
            $this->clock->sleep(60);
        }
        $this->insert('crm');
        self::assertSame(0, $loop->deliverOnce());

        self::assertSame([
            BrokerEvents::WEBHOOK_LANE_OPENED,
            BrokerEvents::WEBHOOK_LANE_RETURNED,
            BrokerEvents::WEBHOOK_LANE_OPENED,
            BrokerEvents::WEBHOOK_LANE_RETURNED,
            BrokerEvents::WEBHOOK_LANE_OPENED,
            BrokerEvents::WEBHOOK_LANE_RETURNED,
            BrokerEvents::WEBHOOK_LANE_BLOCKED,
        ], $this->flowEvents());
        self::assertSame([[
            'lane' => 'crm',
        ]], $this->contextsOf(BrokerEvents::WEBHOOK_LANE_BLOCKED));
        self::assertCount(4, $this->sender->calls, 'the blocked lane is out of the claim after its open time');
    }

    public function testEachLaneOfATransitionGetsItsEvent(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $breaker = new class() implements CircuitBreaker {
            public function openLanes(): array
            {
                return [];
            }

            public function record(array $counts): LaneTransitions
            {
                return new LaneTransitions(['a', 'b'], ['c', 'd'], ['e', 'f']);
            }

            public function policy(): CircuitBreakerPolicy
            {
                return new CircuitBreakerPolicy();
            }
        };

        $this->loop(circuitBreaker: $breaker)->deliverOnce();

        $lanes = array_values(array_map(
            static fn (array $r): string => "{$r['event']} {$r['context']['lane']}",
            array_filter($this->events->records, static fn (array $r): bool => str_starts_with($r['event'], 'webhook.lane_')),
        ));
        self::assertSame([
            'webhook.lane_returned c',
            'webhook.lane_returned d',
            'webhook.lane_opened a',
            'webhook.lane_opened b',
            'webhook.lane_blocked e',
            'webhook.lane_blocked f',
        ], $lanes);
    }

    public function testADatabaseErrorOfTheOpenLanesReadStopsTheRun(): void
    {
        $this->subscribe('crm');
        $breaker = new class() implements CircuitBreaker {
            public function openLanes(): array
            {
                throw new RuntimeException('breaker read failed');
            }

            public function record(array $counts): LaneTransitions
            {
                return new LaneTransitions();
            }

            public function policy(): CircuitBreakerPolicy
            {
                return new CircuitBreakerPolicy();
            }
        };

        $this->expectExceptionMessage('breaker read failed');

        $this->loop(circuitBreaker: $breaker)->run();
    }

    public function testADatabaseErrorOfTheDueCountsStopsTheRunBeforeTheAcquire(): void
    {
        $this->subscribe('crm');
        $store = new FailingDeliveryStore($this->store);
        $store->dueCountsError = new RuntimeException('due count failed');
        $limiter = new StubRateLimiter();

        try {
            $this->loop(store: $store, rateLimiter: $limiter)->run();
            self::fail('expected the error');
        } catch (RuntimeException $e) {
            self::assertSame('due count failed', $e->getMessage());
        }

        self::assertSame([], $limiter->acquires);
    }

    public function testADatabaseErrorOfTheGrantStopsTheRunBeforeTheClaim(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $limiter = new StubRateLimiter(static fn (): array => throw new RuntimeException('bucket update failed'));

        try {
            $this->loop(rateLimiter: $limiter)->run();
            self::fail('expected the error');
        } catch (RuntimeException $e) {
            self::assertSame('bucket update failed', $e->getMessage());
        }

        self::assertSame(0, $this->store->claimCalls);
    }

    public function testADatabaseErrorOfTheGiveBackStopsTheRun(): void
    {
        $this->subscribe('crm');
        $this->insert('crm');
        $limiter = new StubRateLimiter(static fn (array $wanted): array => array_map(static fn (int $count): int => $count + 1, $wanted));
        $limiter->giveBackError = new RuntimeException('give back failed');

        $this->expectExceptionMessage('give back failed');

        $this->loop(rateLimiter: $limiter)->run();
    }

    public function testADatabaseErrorOfTheRecordStopsTheRunAfterTheResultsAreWritten(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm');
        $breaker = new InMemoryCircuitBreaker();
        $breaker->onRecord = static fn () => throw new RuntimeException('breaker write failed');

        try {
            $this->loop(circuitBreaker: $breaker)->run();
            self::fail('expected the error');
        } catch (RuntimeException $e) {
            self::assertSame('breaker write failed', $e->getMessage());
        }

        self::assertSame(DeliveryCompletionKind::Delivered, $this->store->outcome($row->id));
    }

    public function testTheTestsOfTheQueueRunWithAnInMemoryLimiterAndBreakerAsWithNone(): void
    {
        $this->subscribe('crm');
        $this->subscribe('erp');
        $rows = [$this->insert('crm'), $this->insert('crm'), $this->insert('erp')];
        $limiter = new InMemoryRateLimiter();

        self::assertSame(3, $this->loop(rateLimiter: $limiter, circuitBreaker: new InMemoryCircuitBreaker())->deliverOnce());

        foreach ($rows as $row) {
            self::assertSame(DeliveryCompletionKind::Delivered, $this->store->outcome($row->id));
        }
        self::assertSame([], $this->flowEvents(), 'no limit and no trip');
    }

    private function subscribe(string $lane, ?Rate $rateLimit = null): Subscription
    {
        $subscription = new Subscription($lane, "https://{$lane}.example/hook", ['whsec_x'], $rateLimit);
        $this->subscriptions->add($subscription, 'order.placed');

        return $subscription;
    }

    /** @param array<string, mixed> $headers */
    private function insert(string $lane, int $attempts = 0, ?int $createdAt = null, array $headers = [], ?int $availableAt = null): DeliveryRecord
    {
        ++$this->sequence;
        $record = new DeliveryRecord(
            id: sprintf('01900000-0000-7000-8000-%012d', $this->sequence),
            messageId: sprintf('01900000-0000-7000-9000-%012d', $this->sequence),
            lane: $lane,
            messageName: 'order.placed',
            body: sprintf('{"n":%d}', $this->sequence),
            headers: $headers,
            createdAt: $createdAt ?? $this->now - 1_000,
            attempts: $attempts,
            availableAt: $availableAt,
        );
        $this->store->insert([$record]);

        return $record;
    }

    private function onlyDeadLetter(): DeadLetter
    {
        $all = $this->store->deadLetters()->list(new DeadLetterFilter());
        self::assertCount(1, $all);

        return $all[0];
    }

    /**
     * @param list<LaneCounts> $counts
     *
     * @return array<string, array{int, int}> lane => [requests, failures], in lane order
     */
    private static function countsByLane(array $counts): array
    {
        $byLane = [];
        foreach ($counts as $count) {
            self::assertArrayNotHasKey($count->lane, $byLane, 'each lane one time');
            $byLane[$count->lane] = [$count->requests, $count->failures];
        }
        ksort($byLane);

        return $byLane;
    }

    /** @return list<string> the names of the recorded flow control events */
    private function flowEvents(): array
    {
        return array_values(array_filter(
            $this->events->names(),
            static fn (string $name): bool => in_array($name, [
                BrokerEvents::WEBHOOK_LANE_OPENED,
                BrokerEvents::WEBHOOK_LANE_RETURNED,
                BrokerEvents::WEBHOOK_LANE_BLOCKED,
                BrokerEvents::WEBHOOK_PERMIT_WAIT,
                BrokerEvents::WEBHOOK_LANE_AT_LIMIT,
            ], true),
        ));
    }

    /** @return list<array<string, mixed>> the contexts of the recorded events of one name, in order */
    private function contextsOf(string $event): array
    {
        return array_values(array_map(
            static fn (array $r): array => $r['context'],
            array_filter($this->events->records, static fn (array $r): bool => $r['event'] === $event),
        ));
    }

    /**
     * @param array{limitForEachLane: array<array-key, int>, total: int, leaseMs: int} $claim
     *
     * @return list<string> the lanes of a claim, in the order of the keys
     */
    private static function lanesOf(array $claim): array
    {
        return array_map(strval(...), array_keys($claim['limitForEachLane']));
    }

    /**
     * @param array{limitForEachLane: array<array-key, int>, total: int, leaseMs: int} $claim
     *
     * @return array<array-key, int> the row limit of each lane of a claim, in lane order
     */
    private static function sortedLimits(array $claim): array
    {
        $limits = $claim['limitForEachLane'];
        ksort($limits);

        return $limits;
    }

    private function loop(
        ?DeliveryLoopConfig $config = null,
        ?DeliveryRetryPolicy $policy = null,
        ?Randomizer $randomizer = null,
        ?DeliveryStore $store = null,
        RateLimiter $rateLimiter = new NoRateLimit(),
        CircuitBreaker $circuitBreaker = new NoCircuitBreaker(),
    ): DeliveryLoop {
        return new DeliveryLoop(
            store: $store ?? $this->store,
            sender: $this->sender,
            subscriptions: $this->subscriptions,
            retryPolicy: $policy ?? new DefaultDeliveryRetryPolicy(),
            config: $config ?? new DeliveryLoopConfig(idleSleepMs: 1),
            randomizer: $randomizer,
            logger: $this->logger,
            events: $this->events,
            rateLimiter: $rateLimiter,
            circuitBreaker: $circuitBreaker,
        );
    }
}
