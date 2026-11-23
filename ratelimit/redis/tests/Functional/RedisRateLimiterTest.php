<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Functional;

use Closure;
use Freyr\MessageBroker\RateLimit\Redis\PhpRedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\PredisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimitConfig;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimiter;
use Freyr\MessageBroker\RateLimit\Redis\RedisScriptError;
use Freyr\MessageBroker\RateLimit\Redis\RedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\Testing\RedisTestSupport;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Redis rules of the rate limiter: the keys, the time to live, an expired key, the script cache, the time and the errors. */
final class RedisRateLimiterTest extends TestCase
{
    /** @return iterable<string, array{Closure(): RedisScriptRunner}> */
    public static function bridges(): iterable
    {
        yield 'phpredis' => [
            static fn (): RedisScriptRunner => new PhpRedisScriptRunner(RedisTestSupport::phpRedis()),
        ];
        yield 'predis' => [
            static fn (): RedisScriptRunner => new PredisScriptRunner(RedisTestSupport::predis()),
        ];
    }

    /** @param Closure(): RedisScriptRunner $runner */
    #[DataProvider('bridges')]
    public function testEachKeyIsAHashWithTheTokensAndTheRedisTimeAndTheTimeToLiveOfAFullRefill(Closure $runner): void
    {
        $config = new RedisRateLimitConfig(RedisTestSupport::keyPrefix('keys'));
        $limiter = new RedisRateLimiter($runner(), $config);
        $redis = RedisTestSupport::phpRedis();

        $limiter->acquire([
            'crm' => 2,
            'erp' => 1,
        ], 100, new RateLimits(new Rate(2, 4), [
            'crm' => new Rate(0.5, 3),
        ]));
        /** @var array{0: string, 1: string} $time */
        $time = $redis->time();
        $now = (int) $time[0] * 1000 + intdiv((int) $time[1], 1000);

        self::assertSame([$config->globalKey(), $config->laneKey('crm')], RedisTestSupport::keysOf($config->keyPrefix), 'no key for the lane with no limit');
        $global = $redis->hGetAll($config->globalKey());
        $lane = $redis->hGetAll($config->laneKey('crm'));
        self::assertIsArray($global);
        self::assertIsArray($lane);
        self::assertSame(['tokens', 'ts'], array_keys($global));
        self::assertSame('1', $global['tokens'], '4 - 3');
        self::assertSame('1', $lane['tokens'], '3 - 2');
        self::assertMatchesRegularExpression('/^\d{13}$/', (string) $global['ts']);
        self::assertEqualsWithDelta($now, (int) $global['ts'], 2_000, 'the milliseconds of the Redis clock');
        self::assertSame($global['ts'], $lane['ts'], 'one TIME for each call');
        self::assertEqualsWithDelta(3_000, $redis->pttl($config->globalKey()), 1_000, 'ceil(4 * 1000 / 2) + 1000');
        self::assertGreaterThan(2_000, $redis->pttl($config->globalKey()));
        self::assertEqualsWithDelta(7_000, $redis->pttl($config->laneKey('crm')), 1_000, 'ceil(3 * 1000 / 0.5) + 1000');
        self::assertGreaterThan(6_000, $redis->pttl($config->laneKey('crm')));
    }

    /** @param Closure(): RedisScriptRunner $runner */
    #[DataProvider('bridges')]
    public function testWithNoGlobalRateNoGlobalKeyIsReadOrWritten(Closure $runner): void
    {
        $config = new RedisRateLimitConfig(RedisTestSupport::keyPrefix('noglobal'));
        $redis = RedisTestSupport::phpRedis();
        $redis->hMSet($config->globalKey(), [
            'tokens' => '0.5',
            'ts' => '1000',
        ]);
        $redis->pExpire($config->globalKey(), 60_000);
        $limiter = new RedisRateLimiter($runner(), $config);
        $limits = new RateLimits(null, [
            'crm' => new Rate(1, 4),
        ]);

        self::assertSame([
            'crm' => 4,
        ], $limiter->acquire([
            'crm' => 9,
        ], 100, $limits));
        $limiter->giveBack([
            'crm' => 1,
        ], $limits);

        self::assertSame([
            'tokens' => '0.5',
            'ts' => '1000',
        ], $redis->hGetAll($config->globalKey()));
    }

    /** @param Closure(): RedisScriptRunner $runner */
    #[DataProvider('bridges')]
    public function testADeletedKeyActsAsAFullBucket(Closure $runner): void
    {
        $config = new RedisRateLimitConfig(RedisTestSupport::keyPrefix('expired'));
        $limiter = new RedisRateLimiter($runner(), $config);
        $limits = new RateLimits(new Rate(1, 5), [
            'crm' => new Rate(1, 3),
        ]);
        $wanted = [
            'crm' => 9,
        ];
        self::assertSame([
            'crm' => 3,
        ], $limiter->acquire($wanted, 100, $limits));
        self::assertSame([
            'crm' => 0,
        ], $limiter->acquire($wanted, 100, $limits));

        RedisTestSupport::phpRedis()->del($config->laneKey('crm'));

        self::assertSame([
            'crm' => 2,
        ], $limiter->acquire($wanted, 100, $limits), 'a full lane bucket of 3, and 2 left in the global bucket');
        RedisTestSupport::phpRedis()->del($config->globalKey(), $config->laneKey('crm'));
        self::assertSame([
            'crm' => 3,
        ], $limiter->acquire($wanted, 100, $limits), 'both buckets full');
    }

    /** @param Closure(): RedisScriptRunner $runner */
    #[DataProvider('bridges')]
    public function testAScriptFlushBeforeACallDoesNotFailTheCall(Closure $runner): void
    {
        $limiter = new RedisRateLimiter($runner(), new RedisRateLimitConfig(RedisTestSupport::keyPrefix('flush')));
        $limits = new RateLimits(new Rate(1, 10), [
            'crm' => new Rate(1, 4),
        ]);
        $redis = RedisTestSupport::phpRedis();
        $limiter->acquire([
            'crm' => 1,
        ], 100, $limits);

        $redis->script('flush');
        self::assertSame([
            'crm' => 2,
        ], $limiter->acquire([
            'crm' => 2,
        ], 100, $limits), 'the call after the flush succeeds through EVAL');

        $redis->script('flush');
        $limiter->giveBack([
            'crm' => 3,
        ], $limits);
        self::assertSame([
            'crm' => 4,
        ], $limiter->acquire([
            'crm' => 9,
        ], 100, $limits), 'the give-back after the flush returned 3');
    }

    /** @param Closure(): RedisScriptRunner $runner */
    #[DataProvider('bridges')]
    public function testARedisTimeEarlierThanTheStoredTimeGivesNoRefillAndKeepsTheStoredTime(Closure $runner): void
    {
        $config = new RedisRateLimitConfig(RedisTestSupport::keyPrefix('clock'));
        $redis = RedisTestSupport::phpRedis();
        /** @var array{0: string, 1: string} $time */
        $time = $redis->time();
        $later = (string) ((int) $time[0] * 1000 + 10_000);
        $redis->hMSet($config->laneKey('crm'), [
            'tokens' => '0.5',
            'ts' => $later,
        ]);

        $grants = (new RedisRateLimiter($runner(), $config))->acquire([
            'crm' => 5,
        ], 100, new RateLimits(null, [
            'crm' => new Rate(1, 5),
        ]));

        self::assertSame([
            'crm' => 0,
        ], $grants);
        self::assertSame([
            'tokens' => '0.5',
            'ts' => $later,
        ], $redis->hGetAll($config->laneKey('crm')));
    }

    /** @param Closure(): RedisScriptRunner $runner */
    #[DataProvider('bridges')]
    public function testTheTokensKeepAllTheirDigits(Closure $runner): void
    {
        $config = new RedisRateLimitConfig(RedisTestSupport::keyPrefix('digits'));
        $redis = RedisTestSupport::phpRedis();
        $redis->hMSet($config->laneKey('crm'), [
            'tokens' => '1.3333333333333333',
            'ts' => '99999999999999',
        ]);

        (new RedisRateLimiter($runner(), $config))->acquire([
            'crm' => 5,
        ], 100, new RateLimits(null, [
            'crm' => new Rate(1, 5),
        ]));

        self::assertSame('0.33333333333333326', $redis->hGet($config->laneKey('crm'), 'tokens'));
    }

    /** @param Closure(): RedisScriptRunner $runner */
    #[DataProvider('bridges')]
    public function testAnErrorReplyOfTheScriptThrows(Closure $runner): void
    {
        $config = new RedisRateLimitConfig(RedisTestSupport::keyPrefix('wrongtype'));
        RedisTestSupport::phpRedis()->set($config->globalKey(), 'not a hash', [
            'px' => 60_000,
        ]);

        $this->expectException(RedisScriptError::class);
        $this->expectExceptionMessage('WRONGTYPE');
        (new RedisRateLimiter($runner(), $config))->acquire([
            'crm' => 1,
        ], 100, new RateLimits(new Rate(1, 5)));
    }
}
