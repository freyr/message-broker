<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimiter;
use Freyr\MessageBroker\Webhook\RateLimits;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests of the {@see RateLimiter} port. An adapter extends this
 * class, returns its limiter from createRateLimiter(), moves its stored
 * times back in ageBuckets(), and inherits the tests. The tests use the port
 * only: no SQL, no Redis command.
 *
 * Rules for the adapter:
 *  - createRateLimiter() returns a limiter with no bucket state of an earlier
 *    test: each bucket of a test is new, so it starts full.
 *  - ageBuckets($milliseconds) moves the stored time of each bucket of the
 *    limiter back by that value (an SQL UPDATE of the stored time, a Redis
 *    HINCRBY, a step of a MockClock). The refill is the present time minus
 *    the stored time, so the hook gives the result of a wait, and no test
 *    sleeps.
 *  - The limiter reads the present time from its own source (for a database:
 *    the database time). The tests use rates of 1 or 2 permits each second,
 *    so the real time of one test refills less than one permit.
 *  - An implementation with no bucket (as {@see \Freyr\MessageBroker\Webhook\NoRateLimit})
 *    returns false from hasBuckets(); the cases of a bucket are then skipped.
 */
abstract class RateLimiterContractTestCase extends TestCase
{
    /** A limiter with no bucket state: each bucket of the test starts full. */
    abstract protected function createRateLimiter(): RateLimiter;

    /** Move the stored time of each bucket of the limiter back by $milliseconds. */
    abstract protected function ageBuckets(int $milliseconds): void;

    /** False for an implementation with no bucket: the cases of a bucket are skipped. */
    protected function hasBuckets(): bool
    {
        return true;
    }

    // --- Cases for each implementation: $total caps the grants ---

    public function testTheTotalCapsTheSumOfTheGrantsWithMaxMinDivision(): void
    {
        $limiter = $this->createRateLimiter();
        $noLimit = new RateLimits(null);
        $wanted = [
            'a' => 8,
            'b' => 2,
            'c' => 5,
        ];

        self::assertSame([
            'a' => 4,
            'b' => 2,
            'c' => 4,
        ], $limiter->acquire($wanted, 10, $noLimit));
        self::assertSame([
            'a' => 5,
            'b' => 2,
            'c' => 4,
        ], $limiter->acquire($wanted, 11, $noLimit), 'the rest goes to the first lane above the level');
    }

    public function testEachLaneGetsItsWantedCountWhenTheTotalIsEnough(): void
    {
        $limiter = $this->createRateLimiter();

        self::assertSame([
            'a' => 3,
            'b' => 1,
        ], $limiter->acquire([
            'a' => 3,
            'b' => 1,
        ], 4, new RateLimits(null)));
    }

    public function testNoLaneGetsNoGrant(): void
    {
        self::assertSame([], $this->createRateLimiter()->acquire([], 10, new RateLimits(new Rate(1, 5))));
    }

    public function testANumericLaneIdKeepsItsKeyInTheResult(): void
    {
        $limiter = $this->createRateLimiter();

        $grants = $limiter->acquire([
            '42' => 3,
            'crm' => 1,
        ], 10, new RateLimits(null));

        self::assertSame([
            '42' => 3,
            'crm' => 1,
        ], $grants);
        self::assertSame(3, $grants['42']);
        $limiter->giveBack([
            '42' => 1,
        ], new RateLimits(null));
    }

    // --- Cases of a bucket ---

    public function testALaneLimitCapsItsLaneWhileASecondLaneStillGetsPermits(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(null, [
            'a' => new Rate(1, 3),
        ]);
        $wanted = [
            'a' => 5,
            'b' => 5,
        ];

        self::assertSame([
            'a' => 3,
            'b' => 5,
        ], $limiter->acquire($wanted, 100, $limits));
        self::assertSame([
            'a' => 0,
            'b' => 5,
        ], $limiter->acquire($wanted, 100, $limits), 'the bucket of lane a is empty');
    }

    public function testTheGlobalLimitCapsTheSumOfTheGrantsOfAllLanes(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(new Rate(1, 6));
        $wanted = [
            'a' => 5,
            'b' => 5,
        ];

        self::assertSame([
            'a' => 3,
            'b' => 3,
        ], $limiter->acquire($wanted, 100, $limits));
        self::assertSame([
            'a' => 0,
            'b' => 0,
        ], $limiter->acquire($wanted, 100, $limits), 'the global bucket is empty');
    }

    public function testTheGlobalGrantIsDividedMaxMinWithTheFixedVectors(): void
    {
        $this->requireBuckets();
        $wanted = [
            'a' => 20,
            'b' => 2,
            'c' => 5,
        ];
        $laneA = [
            'a' => new Rate(1, 8),
        ];

        self::assertSame([
            'a' => 4,
            'b' => 2,
            'c' => 4,
        ], $this->createRateLimiter()->acquire($wanted, 100, new RateLimits(new Rate(1, 10), $laneA)));
        self::assertSame([
            'a' => 5,
            'b' => 2,
            'c' => 4,
        ], $this->createRateLimiter()->acquire($wanted, 100, new RateLimits(new Rate(1, 11), $laneA)));
    }

    public function testTheTotalAndTheGlobalBucketBothCapTheSum(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(new Rate(1, 10));

        self::assertSame([
            'a' => 4,
        ], $limiter->acquire([
            'a' => 9,
        ], 4, $limits), 'the total is below the global bucket');
        self::assertSame([
            'a' => 6,
        ], $limiter->acquire([
            'a' => 9,
        ], 100, $limits), 'the global bucket has 6 left');
    }

    public function testALaneWithNoLimitGetsPermitsFromTheGlobalBucketOnly(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(new Rate(1, 5), [
            'a' => new Rate(1, 2),
        ]);

        self::assertSame([
            'b' => 4,
        ], $limiter->acquire([
            'b' => 4,
        ], 100, $limits));
        self::assertSame([
            'b' => 1,
        ], $limiter->acquire([
            'b' => 4,
        ], 100, $limits));
    }

    public function testANewLaneStartsFull(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(null, [
            'a' => new Rate(1, 4),
            'b' => new Rate(1, 3),
        ]);
        $limiter->acquire([
            'a' => 4,
        ], 100, $limits);

        self::assertSame([
            'b' => 3,
            'a' => 0,
        ], $limiter->acquire([
            'b' => 9,
            'a' => 9,
        ], 100, $limits));
    }

    public function testAChangedRateOfALaneAppliesAtTheNextCall(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $wanted = [
            'a' => 5,
        ];

        self::assertSame([
            'a' => 2,
        ], $limiter->acquire([
            'a' => 2,
        ], 100, new RateLimits(null, [
            'a' => new Rate(1, 5),
        ])), '3 permits stay in the bucket');
        self::assertSame([
            'a' => 2,
        ], $limiter->acquire($wanted, 100, new RateLimits(null, [
            'a' => new Rate(1, 2),
        ])), 'the new burst capacity caps the 3 permits');
        self::assertSame([
            'a' => 0,
        ], $limiter->acquire($wanted, 100, new RateLimits(null, [
            'a' => new Rate(1, 10),
        ])), 'a larger burst capacity adds no permit');
    }

    public function testAGiveBackReturnsPermitsToEachLaneBucketAndToTheGlobalBucket(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(new Rate(1, 10), [
            'a' => new Rate(1, 4),
        ]);
        $limiter->acquire([
            'a' => 4,
            'b' => 6,
        ], 100, $limits);

        $limiter->giveBack([
            'a' => 2,
            'b' => 1,
        ], $limits);

        self::assertSame([
            'a' => 2,
        ], $limiter->acquire([
            'a' => 4,
        ], 100, $limits), 'lane a got 2 back');
        self::assertSame([
            'b' => 1,
        ], $limiter->acquire([
            'b' => 4,
        ], 100, $limits), 'the global bucket got 3 back');
    }

    public function testAGiveBackFillsEachBucketAtMostToItsBurstCapacity(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(new Rate(1, 10), [
            'a' => new Rate(1, 4),
        ]);
        $limiter->acquire([
            'a' => 1,
            'b' => 1,
        ], 100, $limits);

        $limiter->giveBack([
            'a' => 100,
            'b' => 100,
        ], $limits);

        self::assertSame([
            'a' => 4,
            'b' => 6,
        ], $limiter->acquire([
            'a' => 100,
            'b' => 100,
        ], 100, $limits));
    }

    public function testAGiveBackWithNoGlobalBucketReturnsPermitsToTheLaneBucket(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(null, [
            'a' => new Rate(1, 4),
        ]);
        $limiter->acquire([
            'a' => 4,
        ], 100, $limits);

        $limiter->giveBack([
            'a' => 3,
        ], $limits);

        self::assertSame([
            'a' => 3,
        ], $limiter->acquire([
            'a' => 9,
        ], 100, $limits));
    }

    public function testAnEmptyGlobalBucketIsFullAgainAfterTheTimeOfItsBurstAndNeverAboveFull(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(new Rate(2, 4));
        $wanted = [
            'a' => 100,
        ];
        $limiter->acquire($wanted, 100, $limits);

        $this->ageBuckets(1_000);
        self::assertSame([
            'a' => 2,
        ], $limiter->acquire($wanted, 100, $limits), 'half the burst time refills half the burst');

        $this->ageBuckets(2_000);
        self::assertSame([
            'a' => 4,
        ], $limiter->acquire($wanted, 100, $limits), 'burst / rate refills the burst');

        $this->ageBuckets(60_000);
        self::assertSame([
            'a' => 4,
        ], $limiter->acquire($wanted, 100, $limits), 'never above full');
    }

    public function testAnEmptyLaneBucketIsFullAgainAfterTheTimeOfItsBurstAndNeverAboveFull(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(null, [
            'a' => new Rate(2, 4),
        ]);
        $wanted = [
            'a' => 100,
        ];
        $limiter->acquire($wanted, 100, $limits);

        $this->ageBuckets(1_000);
        self::assertSame([
            'a' => 2,
        ], $limiter->acquire($wanted, 100, $limits), 'half the burst time refills half the burst');

        $this->ageBuckets(2_000);
        self::assertSame([
            'a' => 4,
        ], $limiter->acquire($wanted, 100, $limits), 'burst / rate refills the burst');

        $this->ageBuckets(60_000);
        self::assertSame([
            'a' => 4,
        ], $limiter->acquire($wanted, 100, $limits), 'never above full');
    }

    public function testWithNoGlobalRateTheLaneBucketsCapTheirLanesAndTheTotalCapsTheSum(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(null, [
            'a' => new Rate(1, 3),
        ]);
        $wanted = [
            'a' => 5,
            'b' => 5,
        ];

        self::assertSame([
            'a' => 3,
            'b' => 3,
        ], $limiter->acquire($wanted, 6, $limits), 'the total of 6 is divided max-min');
        self::assertSame([
            'a' => 0,
            'b' => 5,
        ], $limiter->acquire($wanted, 100, $limits), 'lane a is empty, lane b has no bucket');
        self::assertSame([
            'a' => 0,
            'b' => 5,
        ], $limiter->acquire($wanted, 100, $limits), 'no global bucket empties');
    }

    public function testANumericLaneIdFindsItsLaneLimit(): void
    {
        $this->requireBuckets();
        $limiter = $this->createRateLimiter();
        $limits = new RateLimits(null, [
            '42' => new Rate(1, 2),
        ]);

        self::assertSame([
            '42' => 2,
            'crm' => 5,
        ], $limiter->acquire([
            '42' => 5,
            'crm' => 5,
        ], 100, $limits));

        $limiter->giveBack([
            '42' => 1,
        ], $limits);

        self::assertSame([
            '42' => 1,
        ], $limiter->acquire([
            '42' => 5,
        ], 100, $limits));
    }

    private function requireBuckets(): void
    {
        if (!$this->hasBuckets()) {
            self::markTestSkipped('The implementation has no bucket.');
        }
    }
}
