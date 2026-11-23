<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\LaneTransitions;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests of the {@see CircuitBreaker} port. An adapter extends this
 * class, returns its breaker from createCircuitBreaker(), and inherits the
 * tests. The tests use the port only: no SQL.
 *
 * Rules for the adapter:
 *  - createCircuitBreaker() returns a breaker with NO lane state at the start
 *    of the test.
 *  - The breaker reads the present time from its own source (for a database:
 *    the database time). The contract has no time hook. Most tests use the
 *    default window (5 minutes) and open time (10 minutes), so no window and
 *    no open time ends during the test. The tests of the return and of the
 *    block use an open time of 1 ms and wait for the return with a bounded
 *    poll of openLanes() (at most WAIT_LIMIT_MS); the exact limits of the
 *    window and of the open time are rules of the implementation and have
 *    their own tests.
 *  - recordAtTheSameTime() runs two record() calls one after the other. An
 *    adapter with shared state overrides it to run them at the same time in
 *    two sessions; the test then shows that two calls whose lanes are in
 *    opposite order do not wait for each other in a circle (no deadlock).
 */
abstract class CircuitBreakerContractTestCase extends TestCase
{
    private const string LANE = 'contract_a';
    private const string OTHER_LANE = 'contract_b';
    private const int WAIT_LIMIT_MS = 2_000;

    /** A breaker with no lane state. */
    abstract protected function createCircuitBreaker(CircuitBreakerPolicy $policy): CircuitBreaker;

    /**
     * Two record() calls at the same time. The default runs them one after the other on $breaker.
     *
     * @param list<LaneCounts> $first
     * @param list<LaneCounts> $second
     *
     * @return array{LaneTransitions, LaneTransitions} the result of each call
     */
    protected function recordAtTheSameTime(CircuitBreaker $breaker, array $first, array $second): array
    {
        return [$breaker->record($first), $breaker->record($second)];
    }

    public function testANewBreakerHasNoOpenLane(): void
    {
        self::assertSame([], $this->breaker()->openLanes());
    }

    public function testTenRequestsWithSevenFailuresTakeTheLaneOutOfCirculation(): void
    {
        $breaker = $this->breaker();

        $transitions = $breaker->record([new LaneCounts(self::LANE, 10, 7)]);

        self::assertEquals(new LaneTransitions([self::LANE], []), $transitions);
        self::assertSame([self::LANE], $breaker->openLanes());
    }

    public function testTenRequestsWithSixFailuresDoNotTrip(): void
    {
        $breaker = $this->breaker();

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts(self::LANE, 10, 6)]));
        self::assertSame([], $breaker->openLanes());
    }

    public function testNineFailuresOfNineRequestsDoNotTrip(): void
    {
        $breaker = $this->breaker();

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts(self::LANE, 9, 9)]));
        self::assertSame([], $breaker->openLanes());
    }

    public function testTheCountsOfTheBatchesOfOneWindowAddUp(): void
    {
        $breaker = $this->breaker();

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts(self::LANE, 5, 3)]));
        self::assertEquals(new LaneTransitions([self::LANE], []), $breaker->record([new LaneCounts(self::LANE, 5, 4)]));
        self::assertSame([self::LANE], $breaker->openLanes());
    }

    public function testEachLaneHasItsOwnCounts(): void
    {
        $breaker = $this->breaker();

        $transitions = $breaker->record([new LaneCounts(self::LANE, 10, 7), new LaneCounts(self::OTHER_LANE, 10, 6)]);

        self::assertEquals(new LaneTransitions([self::LANE], []), $transitions);
        self::assertSame([self::LANE], $breaker->openLanes());
    }

    public function testOneCallCanTripSeveralLanes(): void
    {
        $breaker = $this->breaker();

        $transitions = $breaker->record([new LaneCounts(self::OTHER_LANE, 10, 10), new LaneCounts(self::LANE, 10, 10)]);

        self::assertEqualsCanonicalizing([self::LANE, self::OTHER_LANE], $transitions->left);
        self::assertSame([], $transitions->returned);
        self::assertEqualsCanonicalizing([self::LANE, self::OTHER_LANE], $breaker->openLanes());
    }

    public function testTheCountsOfAnOpenLaneAreIgnored(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts(self::LANE, 10, 10)]);

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts(self::LANE, 10, 10)]), 'no second trip');
        self::assertSame([self::LANE], $breaker->openLanes());
    }

    public function testTheConfiguredMinimumAndErrorRateDecideTheTrip(): void
    {
        $breaker = $this->createCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 3, errorRatePercent: 50));

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts(self::OTHER_LANE, 2, 2)]));
        self::assertEquals(new LaneTransitions([self::LANE], []), $breaker->record([new LaneCounts(self::LANE, 4, 2)]));
    }

    public function testTwoRecordCallsAtTheSameTimeWithTheLanesInOppositeOrderBothCount(): void
    {
        $breaker = $this->breaker();

        [$first, $second] = $this->recordAtTheSameTime(
            $breaker,
            [new LaneCounts(self::LANE, 5, 5), new LaneCounts(self::OTHER_LANE, 5, 5)],
            [new LaneCounts(self::OTHER_LANE, 5, 5), new LaneCounts(self::LANE, 5, 5)],
        );

        $left = [...$first->left, ...$second->left];
        sort($left);
        self::assertSame([self::LANE, self::OTHER_LANE], $left, 'each lane trips one time, with the counts of both calls');
        self::assertSame([], [...$first->returned, ...$second->returned]);
        self::assertEqualsCanonicalizing([self::LANE, self::OTHER_LANE], $breaker->openLanes());
    }

    public function testThePolicyIsThePolicyOfTheBreaker(): void
    {
        $policy = new CircuitBreakerPolicy(minimumRequests: 3, slowLimitMs: 0, maxConsecutiveOpenings: 5);

        self::assertEquals($policy, $this->createCircuitBreaker($policy)->policy());
    }

    public function testTheFirstRecordAfterTheOpenTimeReportsTheReturnOneTime(): void
    {
        $breaker = $this->createCircuitBreaker(new CircuitBreakerPolicy(openMs: 1));
        $breaker->record([new LaneCounts(self::LANE, 10, 10)]);

        $this->waitForTheReturn($breaker, self::LANE);

        self::assertEquals(new LaneTransitions([], [self::LANE]), $breaker->record([new LaneCounts(self::LANE, 1, 0)]));
        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts(self::LANE, 1, 0)]), 'one return for each trip');
    }

    public function testTheFourthTripInARowBlocksTheLane(): void
    {
        $breaker = $this->createCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 1, openMs: 1));

        self::assertEquals(new LaneTransitions([self::LANE], []), $breaker->record([new LaneCounts(self::LANE, 1, 1)]));
        $this->waitForTheReturn($breaker, self::LANE);
        self::assertEquals(new LaneTransitions([self::LANE], [self::LANE]), $breaker->record([new LaneCounts(self::LANE, 1, 1)]), 'the second trip');
        $this->waitForTheReturn($breaker, self::LANE);
        self::assertEquals(new LaneTransitions([self::LANE], [self::LANE]), $breaker->record([new LaneCounts(self::LANE, 1, 1)]), 'the third trip');
        $this->waitForTheReturn($breaker, self::LANE);

        self::assertEquals(
            new LaneTransitions([], [self::LANE], [self::LANE]),
            $breaker->record([new LaneCounts(self::LANE, 1, 1)]),
            'the fourth trip blocks in place of a timed opening',
        );
        self::assertSame([self::LANE], $breaker->openLanes());
    }

    public function testABlockedLaneStaysOutOfCirculationAfterTheOpenTimeAndItsCountsAreIgnored(): void
    {
        $breaker = $this->createCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 1, openMs: 1, maxConsecutiveOpenings: 1));
        $breaker->record([new LaneCounts(self::LANE, 1, 1)]);
        $this->waitForTheReturn($breaker, self::LANE);
        self::assertEquals(new LaneTransitions([], [self::LANE], [self::LANE]), $breaker->record([new LaneCounts(self::LANE, 1, 1)]));

        // Many open times of 1 ms pass; the lane stays out of circulation in each read.
        $until = hrtime(true) + 50_000_000;
        do {
            self::assertSame([self::LANE], $breaker->openLanes());
            usleep(1_000);
        } while (hrtime(true) < $until);

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts(self::LANE, 1, 1)]), 'no return and no new trip');
        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts(self::LANE, 1, 0)]));
        self::assertSame([self::LANE], $breaker->openLanes());
    }

    public function testAMaximumOfZeroNeverBlocks(): void
    {
        $breaker = $this->createCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 1, openMs: 1, maxConsecutiveOpenings: 0));
        self::assertEquals(new LaneTransitions([self::LANE], []), $breaker->record([new LaneCounts(self::LANE, 1, 1)]));

        for ($trip = 2; $trip <= 5; ++$trip) {
            $this->waitForTheReturn($breaker, self::LANE);
            self::assertEquals(
                new LaneTransitions([self::LANE], [self::LANE]),
                $breaker->record([new LaneCounts(self::LANE, 1, 1)]),
                "trip {$trip} is a timed opening",
            );
        }
    }

    /** Poll openLanes() until the lane is in circulation again, at most WAIT_LIMIT_MS. */
    protected function waitForTheReturn(CircuitBreaker $breaker, string $lane): void
    {
        $deadline = hrtime(true) + self::WAIT_LIMIT_MS * 1_000_000;
        while (in_array($lane, $breaker->openLanes(), true)) {
            if (hrtime(true) > $deadline) {
                self::fail(sprintf('The lane "%s" did not return within %d ms.', $lane, self::WAIT_LIMIT_MS));
            }
            usleep(1_000);
        }
    }

    private function breaker(): CircuitBreaker
    {
        return $this->createCircuitBreaker(new CircuitBreakerPolicy());
    }
}
