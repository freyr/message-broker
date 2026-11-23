<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Testing\RateLimiterContractTestCase;
use Freyr\MessageBroker\Webhook\NoRateLimit;
use Freyr\MessageBroker\Webhook\RateLimiter;

/** NoRateLimit has no bucket: it keeps the cases of the contract in which only $total caps the grants. */
final class NoRateLimitContractTest extends RateLimiterContractTestCase
{
    protected function createRateLimiter(): RateLimiter
    {
        return new NoRateLimit();
    }

    protected function ageBuckets(int $milliseconds): void {}

    protected function hasBuckets(): bool
    {
        return false;
    }
}
