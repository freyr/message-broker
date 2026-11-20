<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateTest extends TestCase
{
    public function testHoldsTheRateAndTheBurst(): void
    {
        $rate = new Rate(50, 7);

        self::assertSame(50.0, $rate->perSecond);
        self::assertSame(7, $rate->burst);
    }

    public function testAcceptsARateBelowOneEachSecondAndABurstOfOne(): void
    {
        $rate = new Rate(0.5, 1);

        self::assertSame(0.5, $rate->perSecond, '30 requests each minute');
        self::assertSame(1, $rate->burst);
        self::assertSame(0.001, (new Rate(0.001))->perSecond);
    }

    /** @return iterable<string, array{float, int}> */
    public static function defaultBursts(): iterable
    {
        yield 'one tenth of 100' => [100, 10];
        yield 'one tenth of 109, a whole number' => [109, 10];
        yield 'one tenth of 20' => [20, 2];
        yield 'one tenth of 19.9 is below 2' => [19.9, 1];
        yield 'one tenth of 19 is below 2' => [19, 1];
        yield 'one tenth of 10' => [10, 1];
        yield 'the minimum of 1 for 9' => [9, 1];
        yield 'the minimum of 1 for 1' => [1, 1];
        yield 'the minimum of 1 for 0.5' => [0.5, 1];
        yield 'the minimum of 1 for 0.01' => [0.01, 1];
    }

    #[DataProvider('defaultBursts')]
    public function testTheDefaultBurstIsOneTenthOfTheRateWithAMinimumOfOne(float $perSecond, int $burst): void
    {
        self::assertSame($burst, (new Rate($perSecond))->burst);
    }

    /** @return iterable<string, array{float, int|null, string}> */
    public static function invalidRates(): iterable
    {
        yield 'a rate of 0' => [0.0, null, '$perSecond must be above 0 and finite, 0 given'];
        yield 'a negative rate' => [-0.1, 3, '$perSecond must be above 0 and finite, -0.1 given'];
        yield 'NAN' => [NAN, null, '$perSecond must be above 0 and finite, NAN given'];
        yield 'INF' => [INF, 3, '$perSecond must be above 0 and finite, INF given'];
        yield 'a burst of 0' => [10, 0, '$burst must be 1 or more, 0 given'];
        yield 'a negative burst' => [0.5, -1, '$burst must be 1 or more, -1 given'];
    }

    #[DataProvider('invalidRates')]
    public function testRejectsARateThatIsNotAboveZeroOrABurstBelowOne(float $perSecond, ?int $burst, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Rate($perSecond, $burst);
    }

    public function testRateLimitsHoldTheGlobalRateAndTheRateOfEachLane(): void
    {
        $global = new Rate(1_000);
        $crm = new Rate(50);
        $limits = new RateLimits($global, [
            'crm' => $crm,
        ]);

        self::assertSame($global, $limits->global);
        self::assertSame($crm, $limits->forLane('crm'));
        self::assertNull($limits->forLane('erp'), 'a lane that is not in the map has no lane limit');
    }

    public function testRateLimitsAcceptNoGlobalRateAndNoLaneRate(): void
    {
        $limits = new RateLimits(null);

        self::assertNull($limits->global, 'no global bucket');
        self::assertNull($limits->forLane('crm'));
    }

    public function testRateLimitsFindTheRateOfANumericLaneId(): void
    {
        $rate = new Rate(5);
        $limits = new RateLimits(null, [
            '42' => $rate,
        ]);

        self::assertSame($rate, $limits->forLane('42'), 'PHP keeps the key "42" as the integer 42');
    }
}
