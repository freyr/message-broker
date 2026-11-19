<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Webhook\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests of the {@see RateLimiter} port. An adapter extends this
 * class, returns its limiter from createRateLimiter(), and inherits the tests.
 * The tests use the port only: no SQL.
 *
 * Rules for the adapter:
 *  - createRateLimiter() returns a limiter whose bucket is FULL (the burst
 *    capacity) at the start of the test, with no state of an earlier test.
 *  - The limiter reads the present time from its own source (for a database:
 *    the database time). The tests use a rate of 1 permit each second and no
 *    sleep, so the refill during one test is less than one permit; the refill
 *    itself is a rule of the implementation and has its own tests.
 */
abstract class RateLimiterContractTestCase extends TestCase
{
    private const int RATE = 1;
    private const int BURST = 5;

    /** A limiter with a full bucket. */
    abstract protected function createRateLimiter(int $ratePerSecond, int $burstCapacity): RateLimiter;

    public function testAFullBucketGrantsEachWantedPermitUpToTheBurstCapacity(): void
    {
        $limiter = $this->createRateLimiter(self::RATE, self::BURST);

        self::assertSame(3, $limiter->acquire(3));
        self::assertSame(2, $limiter->acquire(2));
    }

    public function testAGrantIsAtMostThePermitsInTheBucket(): void
    {
        $limiter = $this->createRateLimiter(self::RATE, self::BURST);

        self::assertSame(5, $limiter->acquire(8));
        self::assertSame(0, $limiter->acquire(1), 'the bucket is empty');
    }

    public function testAnEmptyBucketGrantsNothing(): void
    {
        $limiter = $this->createRateLimiter(self::RATE, self::BURST);
        $limiter->acquire(self::BURST);

        self::assertSame(0, $limiter->acquire(5));
        self::assertSame(0, $limiter->acquire(1));
    }

    public function testGivenBackPermitsCanBeTakenAgain(): void
    {
        $limiter = $this->createRateLimiter(self::RATE, self::BURST);
        $limiter->acquire(self::BURST);

        $limiter->giveBack(3);

        self::assertSame(3, $limiter->acquire(5));
        self::assertSame(0, $limiter->acquire(1));
    }

    public function testGiveBackNeverFillsTheBucketAboveTheBurstCapacity(): void
    {
        $limiter = $this->createRateLimiter(self::RATE, self::BURST);
        $limiter->acquire(2);

        $limiter->giveBack(4);

        self::assertSame(5, $limiter->acquire(100));
    }

    public function testGiveBackToAFullBucketChangesNothing(): void
    {
        $limiter = $this->createRateLimiter(self::RATE, self::BURST);

        $limiter->giveBack(10);

        self::assertSame(5, $limiter->acquire(100));
    }
}
