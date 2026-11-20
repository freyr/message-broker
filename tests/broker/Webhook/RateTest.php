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

        self::assertSame(50, $rate->perSecond);
        self::assertSame(7, $rate->burst);
    }

    public function testAcceptsTheSmallestValues(): void
    {
        $rate = new Rate(1, 1);

        self::assertSame(1, $rate->perSecond);
        self::assertSame(1, $rate->burst);
    }

    /** @return iterable<string, array{int, int}> */
    public static function defaultBursts(): iterable
    {
        yield 'one tenth of 100' => [100, 10];
        yield 'one tenth of 109, a whole number' => [109, 10];
        yield 'one tenth of 20' => [20, 2];
        yield 'one tenth of 19 is below 2' => [19, 1];
        yield 'one tenth of 10' => [10, 1];
        yield 'the minimum of 1 for 9' => [9, 1];
        yield 'the minimum of 1 for 1' => [1, 1];
    }

    #[DataProvider('defaultBursts')]
    public function testTheDefaultBurstIsOneTenthOfTheRateWithAMinimumOfOne(int $perSecond, int $burst): void
    {
        self::assertSame($burst, (new Rate($perSecond))->burst);
    }

    /** @return iterable<string, array{int, int|null, string}> */
    public static function invalidRates(): iterable
    {
        yield 'a rate of 0' => [0, null, '$perSecond must be 1 or more, 0 given'];
        yield 'a negative rate' => [-5, 3, '$perSecond must be 1 or more, -5 given'];
        yield 'a burst of 0' => [10, 0, '$burst must be 1 or more, 0 given'];
        yield 'a negative burst' => [10, -1, '$burst must be 1 or more, -1 given'];
    }

    #[DataProvider('invalidRates')]
    public function testRejectsARateOrABurstBelowOne(int $perSecond, ?int $burst, string $message): void
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
