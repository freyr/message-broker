<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Functional;

use Freyr\MessageBroker\RateLimit\Redis\PhpRedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\RedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\Testing\RedisTestSupport;

/** The Redis rate limiter with the phpredis bridge keeps the contract of the RateLimiter port. */
final class PhpRedisRateLimiterContractTest extends RedisRateLimiterContractTestCase
{
    protected function runner(): RedisScriptRunner
    {
        return new PhpRedisScriptRunner(RedisTestSupport::phpRedis());
    }
}
