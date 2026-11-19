<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Testing\InMemoryRateLimiter;
use Freyr\MessageBroker\Testing\RateLimiterContractTestCase;
use Freyr\MessageBroker\Webhook\RateLimiter;

/** The in-memory rate limiter keeps the contract of the port: the proof that the fake is honest. */
final class InMemoryRateLimiterContractTest extends RateLimiterContractTestCase
{
    protected function createRateLimiter(int $ratePerSecond, int $burstCapacity): RateLimiter
    {
        return new InMemoryRateLimiter($ratePerSecond, $burstCapacity);
    }
}
