<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Testing\FakeDeliverySender;
use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Testing\InMemorySubscriptionProvider;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Tests\Fixtures\FailingDeliveryStore;
use Freyr\MessageBroker\Time\EpochMillis;
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

            public function longestDelayMs(): int
            {
                return 0;
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
            $this->store->claim(['crm'], 10, 10, 60_000);
        };

        self::assertSame(0, $this->loop()->deliverOnce());

        self::assertNull($this->store->outcome($row->id));
        self::assertSame(0, $this->store->row($row->id)->attempts);
        self::assertSame(0, $this->store->deadLetters()->count(new DeadLetterFilter()));
        self::assertSame([], $this->events->records);
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

    public function testARowOlderThanTheMaximumAgeGivesADeadLetterWithTheReasonExpired(): void
    {
        $old = $this->insert('removed', attempts: 2, createdAt: $this->now - 3 * self::DAY_MS - 1);
        $young = $this->insert('removed', createdAt: $this->now - 3 * self::DAY_MS);

        self::assertSame(0, $this->loop()->deliverOnce());

        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($old->id));
        self::assertNull($this->store->outcome($young->id), 'a row at the maximum age is not older than it');
        self::assertSame(2, $this->store->row($old->id)->attempts, 'the expiry uses no attempt');
        $deadLetter = $this->onlyDeadLetter();
        self::assertSame('webhook:removed', $deadLetter->source);
        self::assertSame($old->messageId, $deadLetter->messageId);
        self::assertSame('expired', $deadLetter->errorClass);
        self::assertSame('The delivery is older than the maximum age of 259200000 ms', $deadLetter->errorMessage);
        self::assertSame(2, $deadLetter->attempts);
        self::assertSame($this->now, $deadLetter->failedAt);
        self::assertSame([], $this->sender->calls);
        self::assertSame([[
            'event' => BrokerEvents::WEBHOOK_EXPIRED,
            'context' => [
                'lane' => 'removed',
                'message_id' => $old->messageId,
                'delivery_id' => $old->id,
                'attempts' => 2,
            ],
        ]], $this->events->records);
        self::assertSame([[
            'before' => $this->now - 3 * self::DAY_MS,
            'limit' => 100,
            'leaseMs' => 60_000,
        ]], $this->store->expiredClaims);
    }

    public function testTheMaximumAgeIsConfiguration(): void
    {
        $old = $this->insert('removed', createdAt: $this->now - 10_001);

        $this->loop(new DeliveryLoopConfig(maxAgeMs: 10_000, idleSleepMs: 1))->deliverOnce();

        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($old->id));
        self::assertSame('The delivery is older than the maximum age of 10000 ms', $this->onlyDeadLetter()->errorMessage);
    }

    public function testTheCheckOfExpiredRowsRunsAtItsInterval(): void
    {
        $loop = $this->loop(new DeliveryLoopConfig(maxAgeMs: 1_000, expiredCheckIntervalMs: 60_000, idleSleepMs: 1));
        $loop->deliverOnce();
        $row = $this->insert('removed', createdAt: $this->now - 2_000);

        $this->clock->sleep(59.999);
        $loop->deliverOnce();
        self::assertNull($this->store->outcome($row->id), 'not yet at the interval');

        $this->clock->sleep(0.001);
        $loop->deliverOnce();
        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($row->id));
        self::assertCount(2, $this->store->expiredClaims);
    }

    public function testTheCheckOfExpiredRowsRepeatsWhileTheClaimIsFull(): void
    {
        $rows = array_map(fn (): DeliveryRecord => $this->insert('removed', createdAt: $this->now - 5_000), range(1, 5));

        $this->loop(new DeliveryLoopConfig(batchSize: 2, limitForEachLane: 2, maxAgeMs: 1_000, idleSleepMs: 1))->deliverOnce();

        foreach ($rows as $row) {
            self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($row->id));
        }
        self::assertCount(3, $this->store->expiredClaims, '2, 2, then 1: a short claim ends the check');
    }

    public function testAnExpiredRowThatTheStoreDoesNotChangeRecordsNoEvent(): void
    {
        $this->insert('removed', createdAt: $this->now - 5_000);
        $store = new FailingDeliveryStore($this->store);
        $store->changeNothing = true;

        $this->loop(new DeliveryLoopConfig(maxAgeMs: 1_000, idleSleepMs: 1), store: $store)->deliverOnce();

        self::assertSame([], $this->events->records);
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
        self::assertSame($this->now + 60_000, $this->store->row($unknown->id)->availableAt, 'the row waits for the lease and then for the maximum age');
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
            'lanes' => ['crm'],
            'limitForEachLane' => 7,
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
        self::assertSame(['crm'], $this->store->claims[1]['lanes'], 'the old list before the interval');
        self::assertEqualsCanonicalizing(['crm', 'erp'], $this->store->claims[2]['lanes']);
    }

    public function testTheLaneFilterKeepsOnlyTheLanesOfTheFilter(): void
    {
        foreach (['crm', 'erp', 'bi'] as $lane) {
            $this->subscribe($lane);
        }

        $this->loop(new DeliveryLoopConfig(laneFilter: ['bi', 'crm', 'unknown'], idleSleepMs: 1))->deliverOnce();

        self::assertEqualsCanonicalizing(['crm', 'bi'], $this->store->claims[0]['lanes']);
        self::assertTrue(array_is_list($this->store->claims[0]['lanes']));
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
        self::assertSame($first, $this->store->claims[0]['lanes']);
        self::assertSame($second, $this->store->claims[1]['lanes']);
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
        self::assertSame([], $this->store->expiredClaims);
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

    public function testTheRunRefusesAMaximumAgeAndALongestRetryDelayThatAreNotShorterThanTheRetention(): void
    {
        $this->subscribe('crm');
        $policy = new DefaultDeliveryRetryPolicy([1_000], maxReceiverDelayMs: self::DAY_MS);
        $loop = $this->loop(new DeliveryLoopConfig(maxAgeMs: 6 * self::DAY_MS, retentionDays: 7, idleSleepMs: 1), $policy);
        $this->store->onClaim = static fn () => $loop->stop(); // a loop that starts ends after one pass

        try {
            $loop->run();
            self::fail('expected the refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'The maximum age (518400000 ms) plus the longest retry delay (86400000 ms) must be shorter than the retention period (7 days)',
                $e->getMessage(),
            );
        }

        self::assertSame(0, $this->store->claimCalls);
    }

    public function testTheRunAcceptsASumOneMillisecondShorterThanTheRetention(): void
    {
        $this->subscribe('crm');
        $policy = new DefaultDeliveryRetryPolicy([1_000], maxReceiverDelayMs: self::DAY_MS - 1);
        $loop = $this->loop(new DeliveryLoopConfig(maxAgeMs: 6 * self::DAY_MS, retentionDays: 7, idleSleepMs: 1), $policy);
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

        $this->insert('crm', createdAt: EpochMillis::now()); // the real time: not expired
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

    public function testALoopWithNoEventListenerDeliversAndExpires(): void
    {
        $this->subscribe('crm');
        $row = $this->insert('crm');
        $old = $this->insert('crm', createdAt: $this->now - 3 * self::DAY_MS - 1);
        $loop = new DeliveryLoop($this->store, $this->sender, $this->subscriptions, config: new DeliveryLoopConfig(idleSleepMs: 1));

        self::assertSame(1, $loop->deliverOnce());

        self::assertSame(DeliveryCompletionKind::Delivered, $this->store->outcome($row->id));
        self::assertSame(DeliveryCompletionKind::Dead, $this->store->outcome($old->id));
    }

    private function subscribe(string $lane): Subscription
    {
        $subscription = new Subscription($lane, "https://{$lane}.example/hook", ['whsec_x']);
        $this->subscriptions->add($subscription, 'order.placed');

        return $subscription;
    }

    /** @param array<string, mixed> $headers */
    private function insert(string $lane, int $attempts = 0, ?int $createdAt = null, array $headers = []): DeliveryRecord
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

    private function loop(
        ?DeliveryLoopConfig $config = null,
        ?DeliveryRetryPolicy $policy = null,
        ?Randomizer $randomizer = null,
        ?DeliveryStore $store = null,
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
        );
    }
}
