<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\LaneState;
use Freyr\MessageBroker\Webhook\LaneStates;
use Freyr\MessageBroker\Webhook\LaneTransitions;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests of the {@see LaneStates} port, with the
 * {@see CircuitBreaker} of the same implementation that makes the state. An
 * adapter extends this class, returns its breaker from
 * createCircuitBreaker(), and inherits the tests. The tests use the ports
 * only: no SQL.
 *
 * Rules for the adapter:
 *  - createCircuitBreaker() returns a breaker with NO lane state at the start
 *    of the test.
 *  - A time can differ from the time of the test by up to CLOCK_TOLERANCE_MS,
 *    for an implementation whose clock is not the clock of PHP.
 *  - The contract has no time hook. The tests of the return, of the block
 *    and of the recovery use an open time of 1 ms and a window of 50 ms, and
 *    wait for their condition with a bounded poll of a port result (at most
 *    WAIT_LIMIT_MS).
 */
abstract class LaneStatesContractTestCase extends TestCase
{
    protected const int CLOCK_TOLERANCE_MS = 1_000;

    private const string LANE = 'contract_a';
    private const string OTHER_LANE = 'contract_b';
    private const int OPEN_MS = 600_000;
    private const int WAIT_LIMIT_MS = 2_000;

    /** A breaker with no lane state. */
    abstract protected function createCircuitBreaker(CircuitBreakerPolicy $policy): CircuitBreaker&LaneStates;

    public function testNoResultGivesNoLaneState(): void
    {
        self::assertSame([], $this->breaker()->all());
    }

    public function testEachLaneWithAResultHasItsCountersInTheOrderOfTheLaneId(): void
    {
        $breaker = $this->breaker();
        $before = EpochMillis::now();

        $breaker->record([new LaneCounts(self::OTHER_LANE, 3, 1), new LaneCounts(self::LANE, 2, 0)]);

        $states = $breaker->all();
        self::assertCount(2, $states);
        [$first, $second] = $states;
        self::assertEquals(new LaneState(self::LANE, $first->windowStartedAt, 2, 0, null, 0, 0, null), $first);
        self::assertEquals(new LaneState(self::OTHER_LANE, $first->windowStartedAt, 3, 1, null, 0, 0, null), $second);
        self::assertGreaterThanOrEqual($before - self::CLOCK_TOLERANCE_MS, $first->windowStartedAt);
        self::assertLessThanOrEqual(EpochMillis::now() + self::CLOCK_TOLERANCE_MS, $first->windowStartedAt);
    }

    public function testATripSetsOpenUntilToTheOpenTimeAfterTheTripAndStartsANewWindow(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts(self::LANE, 4, 1)]);

        $breaker->record([new LaneCounts(self::LANE, 6, 6)]);

        [$state] = $breaker->all();
        self::assertSame(self::LANE, $state->lane);
        self::assertSame(0, $state->requests);
        self::assertSame(0, $state->failures);
        self::assertSame($state->windowStartedAt + self::OPEN_MS, $state->openUntil);
        self::assertSame(1, $state->openedCount);
        self::assertSame(1, $state->consecutiveOpenings);
        self::assertNull($state->blockedAt);
    }

    public function testTheCountsOfAnOpenLaneDoNotChangeItsState(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts(self::LANE, 10, 10)]);
        $tripped = $breaker->all();

        $breaker->record([new LaneCounts(self::LANE, 10, 10)]);

        self::assertEquals($tripped, $breaker->all());
    }

    public function testCloseReturnsTheLaneToCirculationWithANewWindowAndKeepsTheOpenedCount(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts(self::LANE, 10, 10)]);
        [$tripped] = $breaker->all();

        self::assertTrue($breaker->close(self::LANE));

        self::assertSame([], $breaker->openLanes());
        [$state] = $breaker->all();
        self::assertEquals(new LaneState(self::LANE, $state->windowStartedAt, 0, 0, null, 1, 0, null), $state);
        self::assertGreaterThanOrEqual($tripped->windowStartedAt, $state->windowStartedAt);
    }

    public function testAfterACloseTheCountsAddUpAgain(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts(self::LANE, 10, 10)]);
        $breaker->close(self::LANE);

        $breaker->record([new LaneCounts(self::LANE, 4, 1)]);

        [$state] = $breaker->all();
        self::assertSame(4, $state->requests);
        self::assertSame(1, $state->failures);
        self::assertNull($state->openUntil);
    }

    public function testCloseOfALaneWithNoStateReturnsFalseAndAddsNoState(): void
    {
        $breaker = $this->breaker();

        self::assertFalse($breaker->close(self::LANE));
        self::assertSame([], $breaker->all());
    }

    public function testCloseChangesOnlyItsLane(): void
    {
        $breaker = $this->breaker();
        $breaker->record([new LaneCounts(self::LANE, 10, 10), new LaneCounts(self::OTHER_LANE, 10, 10)]);

        $breaker->close(self::LANE);

        self::assertSame([self::OTHER_LANE], $breaker->openLanes());
    }

    public function testTheFirstRecordAfterTheReturnStartsANewWindowWithItsCounts(): void
    {
        $breaker = $this->createCircuitBreaker(new CircuitBreakerPolicy(openMs: 1));
        $breaker->record([new LaneCounts(self::LANE, 10, 10)]);
        $this->waitForTheReturn($breaker, self::LANE);

        $breaker->record([new LaneCounts(self::LANE, 3, 2)]);

        [$state] = $breaker->all();
        self::assertNotNull($state->openUntil);
        self::assertGreaterThanOrEqual($state->openUntil, $state->windowStartedAt);
        self::assertSame(3, $state->requests);
        self::assertSame(2, $state->failures);
        self::assertSame(1, $state->openedCount);
        self::assertSame(1, $state->consecutiveOpenings, 'a return is no recovery');
        self::assertNull($state->blockedAt);
    }

    public function testABlockSetsBlockedAtAndCountsTheTrip(): void
    {
        $breaker = $this->createCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 1, openMs: 1, maxConsecutiveOpenings: 1));
        $breaker->record([new LaneCounts(self::LANE, 1, 1)]);
        $this->waitForTheReturn($breaker, self::LANE);
        $before = EpochMillis::now();

        $breaker->record([new LaneCounts(self::LANE, 1, 1)]);

        [$state] = $breaker->all();
        self::assertNotNull($state->blockedAt);
        self::assertGreaterThanOrEqual($before - self::CLOCK_TOLERANCE_MS, $state->blockedAt);
        self::assertLessThanOrEqual(EpochMillis::now() + self::CLOCK_TOLERANCE_MS, $state->blockedAt);
        self::assertSame(0, $state->requests, 'the trip starts a new window');
        self::assertSame(0, $state->failures);
        self::assertSame(2, $state->openedCount, 'a block takes the lane out of circulation');
        self::assertSame(2, $state->consecutiveOpenings, 'each trip adds 1');
    }

    public function testCloseEndsABlock(): void
    {
        $breaker = $this->createCircuitBreaker(new CircuitBreakerPolicy(minimumRequests: 1, openMs: 1, maxConsecutiveOpenings: 1));
        $breaker->record([new LaneCounts(self::LANE, 1, 1), new LaneCounts(self::OTHER_LANE, 1, 1)]);
        $this->waitForTheReturn($breaker, self::LANE);
        $this->waitForTheReturn($breaker, self::OTHER_LANE);
        $breaker->record([new LaneCounts(self::LANE, 1, 1), new LaneCounts(self::OTHER_LANE, 1, 1)]);
        self::assertSame([self::LANE, self::OTHER_LANE], $breaker->openLanes(), 'both lanes are blocked');

        self::assertTrue($breaker->close(self::LANE));

        self::assertSame([self::OTHER_LANE], $breaker->openLanes());
        [$closed, $blocked] = $breaker->all();
        self::assertEquals(new LaneState(self::LANE, $closed->windowStartedAt, 0, 0, null, 2, 0, null), $closed);
        self::assertNotNull($blocked->blockedAt);
        self::assertEquals(
            new LaneTransitions([self::LANE], []),
            $breaker->record([new LaneCounts(self::LANE, 1, 1)]),
            'after the close the next trip is a timed opening',
        );
    }

    public function testARecoveryWindowSetsTheOpeningsInARowToZero(): void
    {
        $breaker = $this->createCircuitBreaker(new CircuitBreakerPolicy(windowMs: 50, minimumRequests: 1, openMs: 1));
        $breaker->record([new LaneCounts(self::LANE, 1, 1)]);
        $this->waitForTheReturn($breaker, self::LANE);

        // Successes only: the window reaches the minimum and never trips. The
        // first record() after the window length starts the next window and
        // finds the recovery.
        $deadline = hrtime(true) + self::WAIT_LIMIT_MS * 1_000_000;
        do {
            $breaker->record([new LaneCounts(self::LANE, 1, 0)]);
            [$state] = $breaker->all();
            if (hrtime(true) > $deadline) {
                self::fail(sprintf('No recovery within %d ms.', self::WAIT_LIMIT_MS));
            }
        } while ($state->consecutiveOpenings !== 0);

        self::assertSame(1, $state->requests, 'the new window has the counts of the call that started it');
        self::assertSame(1, $state->openedCount, 'a recovery keeps the opened count');
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

    private function breaker(): CircuitBreaker&LaneStates
    {
        return $this->createCircuitBreaker(new CircuitBreakerPolicy(openMs: self::OPEN_MS));
    }
}
