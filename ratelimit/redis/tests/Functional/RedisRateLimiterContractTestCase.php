<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Functional;

use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimitConfig;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimiter;
use Freyr\MessageBroker\RateLimit\Redis\RedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\Testing\RedisTestSupport;
use Freyr\MessageBroker\Testing\RateLimiterContractTestCase;
use Freyr\MessageBroker\Webhook\RateLimiter;

/**
 * The contract of the RateLimiter port for RedisRateLimiter, one time for
 * each bridge. Each limiter gets a new key prefix, so its buckets start
 * full; ageBuckets() moves the stored time of each key of the prefixes of
 * the test back with HINCRBY, and the time to live stays.
 */
abstract class RedisRateLimiterContractTestCase extends RateLimiterContractTestCase
{
    /** @var list<string> the key prefixes of the limiters of this test */
    private array $prefixes = [];

    abstract protected function runner(): RedisScriptRunner;

    protected function createRateLimiter(): RateLimiter
    {
        $prefix = RedisTestSupport::keyPrefix('contract');
        $this->prefixes[] = $prefix;

        return new RedisRateLimiter($this->runner(), new RedisRateLimitConfig($prefix));
    }

    protected function ageBuckets(int $milliseconds): void
    {
        $redis = RedisTestSupport::phpRedis();
        foreach ($this->prefixes as $prefix) {
            foreach (RedisTestSupport::keysOf($prefix) as $key) {
                $redis->hIncrBy($key, 'ts', -$milliseconds);
            }
        }
    }
}
