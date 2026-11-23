<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Unit;

use Freyr\MessageBroker\RateLimit\Redis\LuaScript;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimitConfig;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimiter;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimits;
use PHPUnit\Framework\TestCase;

/** The keys and the argument layout of the spec that RedisRateLimiter gives to the scripts. */
final class RedisRateLimiterTest extends TestCase
{
    public function testAnAcquireWithAGlobalRateGivesTheGlobalKeyFirstAndTheLaneKeysInTheOrderOfTheCall(): void
    {
        $runner = new RecordingScriptRunner([1, 2, 3]);
        $limiter = new RedisRateLimiter($runner, new RedisRateLimitConfig('app'));

        $grants = $limiter->acquire([
            'crm' => 5,
            '42' => 4,
            'erp' => 3,
        ], 9, new RateLimits(new Rate(2.5, 7), [
            'erp' => new Rate(0.5, 2),
            'crm' => new Rate(10, 3),
            'other' => new Rate(1, 1),
        ]));

        self::assertSame([
            'crm' => 1,
            '42' => 2,
            'erp' => 3,
        ], $grants);
        self::assertSame([[
            'sha1' => LuaScript::acquire()->sha1,
            'script' => LuaScript::acquire()->source,
            'keys' => ['{mb-rl:app}:g', '{mb-rl:app}:l:crm', '{mb-rl:app}:l:erp'],
            // flag, total, global rate and burst, N, the wanted counts, then position, rate and burst of each lane key
            'args' => [1, 9, '2.5', 7, 3, 5, 4, 3, 1, '10.0', 3, 3, '0.5', 2],
        ]], $runner->calls);
    }

    public function testAnAcquireWithNoGlobalRateGivesNoGlobalKeyAndZerosForTheGlobalRate(): void
    {
        $runner = new RecordingScriptRunner([2, 0]);
        $limiter = new RedisRateLimiter($runner, new RedisRateLimitConfig('app'));

        $grants = $limiter->acquire([
            7 => 2,
            'b' => 1,
        ], 3, new RateLimits(null, [
            '7' => new Rate(1, 4),
        ]));

        self::assertSame([
            7 => 2,
            'b' => 0,
        ], $grants);
        self::assertSame(['{mb-rl:app}:l:7'], $runner->calls[0]['keys']);
        self::assertSame([0, 3, 0, 0, 2, 2, 1, 1, '1.0', 4], $runner->calls[0]['args']);
    }

    public function testAGiveBackHasTheLayoutOfTheAcquireWithNoTotal(): void
    {
        $runner = new RecordingScriptRunner(2);
        $limiter = new RedisRateLimiter($runner, new RedisRateLimitConfig('app'));

        $limiter->giveBack([
            'a' => 2,
            'b' => 1,
        ], new RateLimits(new Rate(0.25, 3), [
            'b' => new Rate(4, 5),
        ]));
        $limiter->giveBack([
            'a' => 3,
        ], new RateLimits(null));

        self::assertSame([
            [
                'sha1' => LuaScript::giveBack()->sha1,
                'script' => LuaScript::giveBack()->source,
                'keys' => ['{mb-rl:app}:g', '{mb-rl:app}:l:b'],
                // flag, global rate and burst, N, the unused counts, then position, rate and burst of each lane key
                'args' => [1, '0.25', 3, 2, 2, 1, 2, '4.0', 5],
            ],
            [
                'sha1' => LuaScript::giveBack()->sha1,
                'script' => LuaScript::giveBack()->source,
                'keys' => [],
                'args' => [0, 0, 0, 1, 3],
            ],
        ], $runner->calls);
    }

    public function testARateIsGivenWithAllItsDigits(): void
    {
        $runner = new RecordingScriptRunner([1]);

        (new RedisRateLimiter($runner, new RedisRateLimitConfig('app')))->acquire([
            'a' => 1,
        ], 1, new RateLimits(new Rate(1 / 3)));

        self::assertSame('0.3333333333333333', $runner->calls[0]['args'][2]);
    }
}
