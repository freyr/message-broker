<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Redis\Tests\Functional;

use Freyr\MessageBroker\Adapter\Redis\PredisScriptRunner;
use Freyr\MessageBroker\Adapter\Redis\RedisScriptRunner;
use Freyr\MessageBroker\Adapter\Redis\Testing\RedisTestSupport;

/** The Redis rate limiter with the Predis bridge keeps the contract of the RateLimiter port. */
final class PredisRateLimiterContractTest extends RedisRateLimiterContractTestCase
{
    protected function runner(): RedisScriptRunner
    {
        return new PredisScriptRunner(RedisTestSupport::predis());
    }
}
