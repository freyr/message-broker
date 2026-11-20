<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Testing\InMemoryRateLimiter;
use Freyr\MessageBroker\Testing\RateLimiterContractTestCase;
use Freyr\MessageBroker\Webhook\RateLimiter;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/** The in-memory rate limiter keeps the contract of the port: the proof that the fake is honest. */
final class InMemoryRateLimiterContractTest extends RateLimiterContractTestCase
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

    protected function createRateLimiter(): RateLimiter
    {
        return new InMemoryRateLimiter();
    }

    protected function ageBuckets(int $milliseconds): void
    {
        $this->clock->sleep($milliseconds / 1_000);
    }
}
