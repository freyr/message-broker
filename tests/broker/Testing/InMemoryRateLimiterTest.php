<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Testing\InMemoryRateLimiter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/** The rules of the in-memory rate limiter that need the time: the refill and the default burst capacity. */
final class InMemoryRateLimiterTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testTheBucketRefillsAtTheRate(): void
    {
        $limiter = new InMemoryRateLimiter(100, 50);
        $limiter->acquire(50);

        $this->clock->sleep(0.1);

        self::assertSame(10, $limiter->acquire(50), '100 permits each second for 100 ms');
        self::assertSame(0, $limiter->acquire(1));
    }

    public function testAPartOfAPermitStaysInTheBucket(): void
    {
        $limiter = new InMemoryRateLimiter(10, 1);
        $limiter->acquire(1);

        $this->clock->sleep(0.05);
        self::assertSame(0, $limiter->acquire(1), 'half a permit');
        $this->clock->sleep(0.05);
        self::assertSame(1, $limiter->acquire(1));
    }

    public function testTheRefillStopsAtTheBurstCapacity(): void
    {
        $limiter = new InMemoryRateLimiter(100, 20);
        $limiter->acquire(20);

        $this->clock->sleep(60);

        self::assertSame(20, $limiter->acquire(1_000));
    }

    public function testTheDefaultBurstCapacityIsOneTenthOfTheRate(): void
    {
        self::assertSame(100, (new InMemoryRateLimiter(1_000))->burstCapacity);
        self::assertSame(100, (new InMemoryRateLimiter(1_009))->burstCapacity);
        self::assertSame(1, (new InMemoryRateLimiter(10))->burstCapacity);
        self::assertSame(1, (new InMemoryRateLimiter(9))->burstCapacity, 'the minimum is 1');
        self::assertSame(100, (new InMemoryRateLimiter(1_000))->acquire(1_000));
    }

    public function testItRecordsEachGrantAndEachGiveBack(): void
    {
        $limiter = new InMemoryRateLimiter(1, 5);

        $limiter->acquire(3);
        $limiter->giveBack(1);
        $limiter->acquire(9);

        self::assertSame([[
            'wanted' => 3,
            'granted' => 3,
        ], [
            'wanted' => 9,
            'granted' => 3,
        ]], $limiter->acquires);
        self::assertSame([1], $limiter->givenBack);
    }

    public function testOnAcquireRunsBeforeEachGrantWithTheCallNumber(): void
    {
        $limiter = new InMemoryRateLimiter(1, 5);
        $calls = [];
        $limiter->onAcquire = static function (int $call) use (&$calls): void {
            $calls[] = $call;
        };

        $limiter->acquire(1);
        $limiter->acquire(1);

        self::assertSame([1, 2], $calls);
    }

    public function testRejectsARateOrABurstCapacityBelowOne(): void
    {
        try {
            new InMemoryRateLimiter(0);
            self::fail('expected the refusal of the rate');
        } catch (InvalidArgumentException $e) {
            self::assertSame('$ratePerSecond must be 1 or more, 0 given', $e->getMessage());
        }

        $this->expectExceptionMessage('$burstCapacity must be 1 or more, 0 given');
        new InMemoryRateLimiter(10, 0);
    }
}
