<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Testing\InMemoryCircuitBreaker;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\LaneState;
use Freyr\MessageBroker\Webhook\LaneTransitions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/** The rules of the in-memory circuit breaker that need the exact time: the window, the return after open_until, the recovery and the block. */
final class InMemoryCircuitBreakerTest extends TestCase
{
    private MockClock $clock;
    private int $start;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($this->clock);
        $this->start = EpochMillis::now();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testAWindowOlderThanTheWindowLengthStartsAgain(): void
    {
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(windowMs: 300_000));
        $breaker->record([new LaneCounts('crm', 6, 6)]);

        $this->clock->sleep(300.001);
        $transitions = $breaker->record([new LaneCounts('crm', 6, 6)]);

        self::assertEquals(new LaneTransitions(), $transitions, '6 failures, then 6 in a new window');
        self::assertEquals([new LaneState('crm', $this->start + 300_001, 6, 6, null, 0, 0, null)], $breaker->all());
    }

    public function testAWindowOfExactlyTheWindowLengthStillCounts(): void
    {
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(windowMs: 300_000));
        $breaker->record([new LaneCounts('crm', 6, 6)]);

        $this->clock->sleep(300);

        self::assertEquals(new LaneTransitions(['crm'], []), $breaker->record([new LaneCounts('crm', 6, 6)]));
    }

    public function testTheLaneReturnsAtOpenUntilAndTheFirstRecordStartsANewWindowAndReportsTheReturn(): void
    {
        // An open time shorter than the window: the return still starts a new window.
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(windowMs: 300_000, openMs: 60_000));
        $breaker->record([new LaneCounts('crm', 10, 10)]);

        $this->clock->sleep(59.999);
        self::assertSame(['crm'], $breaker->openLanes());
        $this->clock->sleep(0.001);
        self::assertSame([], $breaker->openLanes(), 'at open_until the lane is in circulation');

        self::assertEquals(new LaneTransitions([], ['crm']), $breaker->record([new LaneCounts('crm', 3, 3)]));
        self::assertEquals([new LaneState('crm', $this->start + 60_000, 3, 3, $this->start + 60_000, 1, 1, null)], $breaker->all());
        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts('crm', 1, 0)]), 'one return for each trip');
    }

    public function testAnEndpointThatStillFailsTripsAgainAfterTheMinimumNumberOfRequests(): void
    {
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(openMs: 60_000));
        $breaker->record([new LaneCounts('crm', 10, 10)]);
        $this->clock->sleep(60);

        self::assertEquals(new LaneTransitions(['crm'], ['crm']), $breaker->record([new LaneCounts('crm', 10, 10)]));
        self::assertEquals([new LaneState('crm', $this->start + 60_000, 0, 0, $this->start + 120_000, 2, 2, null)], $breaker->all());
    }

    public function testACloseBeforeOpenUntilGivesNoReturn(): void
    {
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy());
        $breaker->record([new LaneCounts('crm', 10, 10)]);
        $this->clock->sleep(1);
        $breaker->close('crm');

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts('crm', 1, 0)]));
        self::assertEquals([new LaneState('crm', $this->start + 1_000, 1, 0, null, 1, 0, null)], $breaker->all());
    }

    public function testANumericLaneIdStaysAString(): void
    {
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy());

        $breaker->record([new LaneCounts('42', 10, 10), new LaneCounts('7', 1, 0)]);

        self::assertSame(['42'], $breaker->openLanes());
        self::assertSame(['42', '7'], array_map(static fn (LaneState $s): string => $s->lane, $breaker->all()), 'lane-ID order, as strings');
        self::assertTrue($breaker->close('42'));
    }

    public function testItRecordsEachCallAndRunsTheHooks(): void
    {
        $breaker = new InMemoryCircuitBreaker();
        $calls = [];
        $breaker->onRecord = static function (int $call) use (&$calls): void {
            $calls[] = $call;
        };
        $counts = [new LaneCounts('crm', 2, 1)];

        $breaker->openLanes();
        $breaker->record($counts);

        self::assertSame(1, $breaker->openLanesCalls);
        self::assertSame([$counts], $breaker->records);
        self::assertSame([1], $calls);
    }

    public function testAWindowOfTheMinimumOrMoreThatEndsWithNoTripIsARecovery(): void
    {
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(windowMs: 300_000, openMs: 60_000));
        $breaker->record([new LaneCounts('crm', 10, 10)]);
        $this->clock->sleep(60);
        $breaker->record([new LaneCounts('crm', 10, 0)]);

        $this->clock->sleep(300);
        $breaker->record([new LaneCounts('crm', 1, 0)]);
        self::assertSame(1, $breaker->all()[0]->consecutiveOpenings, 'a window of exactly the window length still counts');

        $this->clock->sleep(0.001);
        $breaker->record([new LaneCounts('crm', 1, 0)]);
        self::assertEquals([new LaneState('crm', $this->start + 360_001, 1, 0, $this->start + 60_000, 1, 0, null)], $breaker->all());
    }

    public function testAWindowBelowTheMinimumIsNoRecovery(): void
    {
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(windowMs: 300_000, openMs: 60_000));
        $breaker->record([new LaneCounts('crm', 10, 10)]);
        $this->clock->sleep(60);
        $breaker->record([new LaneCounts('crm', 9, 0)]);

        $this->clock->sleep(301);
        $breaker->record([new LaneCounts('crm', 1, 0)]);

        self::assertSame(1, $breaker->all()[0]->consecutiveOpenings);
    }

    public function testTheFourthTripInARowBlocksAndStaysUntilClose(): void
    {
        $breaker = new InMemoryCircuitBreaker(new CircuitBreakerPolicy(openMs: 60_000));
        for ($trip = 1; $trip <= 3; ++$trip) {
            self::assertSame(['crm'], $breaker->record([new LaneCounts('crm', 10, 10)])->left, "trip {$trip}");
            $this->clock->sleep(60);
        }

        self::assertEquals(new LaneTransitions([], ['crm'], ['crm']), $breaker->record([new LaneCounts('crm', 10, 10)]));
        $blockedAt = $this->start + 180_000;
        self::assertEquals([new LaneState('crm', $blockedAt, 0, 0, $this->start + 180_000, 4, 4, $blockedAt)], $breaker->all());

        $this->clock->sleep(86_400);
        self::assertSame(['crm'], $breaker->openLanes(), 'a block has no time limit');
        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts('crm', 10, 10)]));

        self::assertTrue($breaker->close('crm'));
        self::assertEquals([new LaneState('crm', $blockedAt + 86_400_000, 0, 0, null, 4, 0, null)], $breaker->all());
        self::assertSame([], $breaker->openLanes());
    }

    public function testPolicyReturnsTheConstructorPolicy(): void
    {
        $policy = new CircuitBreakerPolicy(maxConsecutiveOpenings: 7);

        self::assertSame($policy, (new InMemoryCircuitBreaker($policy))->policy());
        self::assertEquals(new CircuitBreakerPolicy(), (new InMemoryCircuitBreaker())->policy());
    }
}
