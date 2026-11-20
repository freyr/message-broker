<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\LaneCounts;
use Freyr\MessageBroker\Webhook\LaneState;
use Freyr\MessageBroker\Webhook\LaneTransitions;
use Freyr\MessageBroker\Webhook\NoCircuitBreaker;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The values of the flow control ports, and the implementations that limit nothing. */
final class FlowControlValuesTest extends TestCase
{
    public function testLaneCountsHoldTheLaneTheRequestsAndTheFailures(): void
    {
        $counts = new LaneCounts('crm', 5, 2);

        self::assertSame('crm', $counts->lane);
        self::assertSame(5, $counts->requests);
        self::assertSame(2, $counts->failures);
    }

    public function testLaneCountsAcceptNoFailureAndOnlyFailures(): void
    {
        self::assertSame(0, (new LaneCounts('crm', 1, 0))->failures);
        self::assertSame(1, (new LaneCounts('crm', 1, 1))->failures);
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function invalidCounts(): iterable
    {
        yield 'no request' => [0, 0, 'The counts of the lane "crm" need 1 or more requests, 0 given'];
        yield 'negative failures' => [1, -1, 'The failures of the lane "crm" must be 0 to 1, -1 given'];
        yield 'more failures than requests' => [2, 3, 'The failures of the lane "crm" must be 0 to 2, 3 given'];
    }

    #[DataProvider('invalidCounts')]
    public function testLaneCountsRejectImpossibleCounts(int $requests, int $failures, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new LaneCounts('crm', $requests, $failures);
    }

    public function testLaneTransitionsAreEmptyByDefault(): void
    {
        $transitions = new LaneTransitions();

        self::assertSame([], $transitions->left);
        self::assertSame([], $transitions->returned);
        self::assertSame([], $transitions->blocked);
    }

    public function testLaneTransitionsHoldTheLanesThatLeftReturnedAndWereBlocked(): void
    {
        $transitions = new LaneTransitions(['crm'], ['erp', 'bi'], ['ads']);

        self::assertSame(['crm'], $transitions->left);
        self::assertSame(['erp', 'bi'], $transitions->returned);
        self::assertSame(['ads'], $transitions->blocked);
    }

    public function testLaneStateHoldsEachField(): void
    {
        $state = new LaneState('crm', 1_000, 12, 9, 601_000, 3, 2, 5_000);

        self::assertSame('crm', $state->lane);
        self::assertSame(1_000, $state->windowStartedAt);
        self::assertSame(12, $state->requests);
        self::assertSame(9, $state->failures);
        self::assertSame(601_000, $state->openUntil);
        self::assertSame(3, $state->openedCount);
        self::assertSame(2, $state->consecutiveOpenings);
        self::assertSame(5_000, $state->blockedAt);
    }

    public function testNoCircuitBreakerHasNoOpenLaneAndNoTransition(): void
    {
        $breaker = new NoCircuitBreaker();

        self::assertEquals(new LaneTransitions(), $breaker->record([new LaneCounts('crm', 100, 100)]));
        self::assertSame([], $breaker->openLanes());
        self::assertEquals(new CircuitBreakerPolicy(), $breaker->policy(), 'the default policy');
    }
}
