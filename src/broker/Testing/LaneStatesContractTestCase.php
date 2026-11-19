<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\CircuitBreaker;
use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\LaneState;
use Freyr\MessageBroker\Webhook\LaneStates;
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
 */
abstract class LaneStatesContractTestCase extends TestCase
{
    protected const int CLOCK_TOLERANCE_MS = 1_000;

    private const string LANE = 'contract_a';
    private const string OTHER_LANE = 'contract_b';
    private const int OPEN_MS = 600_000;

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
        self::assertEquals(new LaneState(self::LANE, $first->windowStartedAt, 2, 0, null, 0), $first);
        self::assertEquals(new LaneState(self::OTHER_LANE, $first->windowStartedAt, 3, 1, null, 0), $second);
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
        self::assertEquals(new LaneState(self::LANE, $state->windowStartedAt, 0, 0, null, 1), $state);
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

    private function breaker(): CircuitBreaker&LaneStates
    {
        return $this->createCircuitBreaker(new CircuitBreakerPolicy(openMs: self::OPEN_MS));
    }
}
