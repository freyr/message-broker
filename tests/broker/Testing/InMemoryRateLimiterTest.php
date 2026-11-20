<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Testing\InMemoryRateLimiter;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimits;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/** The rules of the in-memory rate limiter outside the contract: the exact refill, the records and the call hook. */
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

    public function testAPartOfAPermitStaysInTheBucket(): void
    {
        $limiter = new InMemoryRateLimiter();
        $limits = new RateLimits(new Rate(10, 1), [
            'a' => new Rate(10, 1),
        ]);
        $wanted = [
            'a' => 1,
        ];
        $limiter->acquire($wanted, 10, $limits);

        $this->clock->sleep(0.05);
        self::assertSame([
            'a' => 0,
        ], $limiter->acquire($wanted, 10, $limits), 'half a permit');
        $this->clock->sleep(0.05);
        self::assertSame([
            'a' => 1,
        ], $limiter->acquire($wanted, 10, $limits));
    }

    public function testAClockThatGoesBackRefillsNothing(): void
    {
        $limiter = new InMemoryRateLimiter();
        $limits = new RateLimits(new Rate(10, 5));
        $wanted = [
            'a' => 5,
        ];
        $limiter->acquire($wanted, 10, $limits);

        $this->clock->modify('-10 seconds');

        self::assertSame([
            'a' => 0,
        ], $limiter->acquire($wanted, 10, $limits));
    }

    public function testItRecordsEachAcquireAndEachGiveBack(): void
    {
        $limiter = new InMemoryRateLimiter();
        $limits = new RateLimits(new Rate(1, 5));

        $limiter->acquire([
            'a' => 3,
        ], 10, $limits);
        $limiter->giveBack([
            'a' => 1,
        ], $limits);
        $limiter->acquire([
            'a' => 9,
        ], 2, $limits);

        self::assertSame([[
            'wanted' => [
                'a' => 3,
            ],
            'total' => 10,
            'granted' => [
                'a' => 3,
            ],
        ], [
            'wanted' => [
                'a' => 9,
            ],
            'total' => 2,
            'granted' => [
                'a' => 2,
            ],
        ]], $limiter->acquires);
        self::assertSame([[
            'a' => 1,
        ]], $limiter->givenBack);
    }

    public function testOnAcquireRunsBeforeEachGrantWithTheCallNumber(): void
    {
        $limiter = new InMemoryRateLimiter();
        $calls = [];
        $limiter->onAcquire = static function (int $call) use (&$calls, $limiter): void {
            $calls[] = [$call, count($limiter->acquires)];
        };

        $limiter->acquire([
            'a' => 1,
        ], 1, new RateLimits(null));
        $limiter->acquire([
            'a' => 1,
        ], 1, new RateLimits(null));

        self::assertSame([[1, 0], [2, 1]], $calls);
    }
}
