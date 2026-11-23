<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Functional;

use Freyr\MessageBroker\RateLimit\Redis\LuaScript;
use Freyr\MessageBroker\RateLimit\Redis\PhpRedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimitConfig;
use Freyr\MessageBroker\RateLimit\Redis\Testing\RedisTestSupport;
use Freyr\MessageBroker\Webhook\PermitDivision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Lua scripts with the argument layout of the spec, run directly. The
 * division of acquire.lua gives the same grants as PermitDivision::divide()
 * of the core, for the fixed vectors of the spec and for a fixed set of
 * seeded vectors.
 */
final class RateLimitScriptsTest extends TestCase
{
    public function testTheFixedVectorsOfTheSpecWithNoKey(): void
    {
        self::assertSame([4, 2, 4], $this->acquireWithNoKey([8, 2, 5], 10));
        self::assertSame([5, 2, 4], $this->acquireWithNoKey([8, 2, 5], 11));
    }

    public function testTheFixedVectorsOfTheSpecWithAGlobalKeyAndALaneKey(): void
    {
        // Lane 1 has a bucket of 8; the global bucket holds the global grant.
        self::assertSame([4, 2, 4], $this->acquireWithKeys([20, 2, 5], 100, 10, [
            1 => 8,
        ]));
        self::assertSame([5, 2, 4], $this->acquireWithKeys([20, 2, 5], 100, 11, [
            1 => 8,
        ]));
    }

    /** @return iterable<string, array{list<int>, int}> */
    public static function seededVectors(): iterable
    {
        mt_srand(20261120);
        for ($case = 0; $case < 150; ++$case) {
            $lanes = mt_rand(1, 8);
            $available = [];
            for ($lane = 0; $lane < $lanes; ++$lane) {
                $available[] = mt_rand(1, 20);
            }
            $grant = mt_rand(0, array_sum($available) + 2);
            yield "case {$case}" => [$available, $grant];
        }
        mt_srand();
    }

    /** @param list<int> $available */
    #[DataProvider('seededVectors')]
    public function testTheDivisionWithNoKeyIsTheDivisionOfTheCore(array $available, int $grant): void
    {
        self::assertSame(PermitDivision::divide($available, $grant), $this->acquireWithNoKey($available, $grant));
    }

    /** @param list<int> $available */
    #[DataProvider('seededVectors')]
    public function testTheDivisionWithLaneBucketsAndAGlobalBucketIsTheDivisionOfTheCore(array $available, int $grant): void
    {
        // Each odd lane gets its availability from a lane bucket (wanted 25), each even lane from its wanted count.
        $wanted = [];
        $buckets = [];
        foreach ($available as $position => $availability) {
            if ($position % 2 === 1) {
                $buckets[$position] = $availability;
                $wanted[] = 25;
            } else {
                $wanted[] = $availability;
            }
        }
        $globalBurst = max(1, $grant);
        $total = $grant;

        self::assertSame(
            PermitDivision::divide($available, min($total, $globalBurst)),
            $this->acquireWithKeys($wanted, $total, $globalBurst, $buckets),
        );
    }

    public function testTheGiveBackLayoutAddsEachUnusedCountToItsLaneKeyAndTheSumToTheGlobalKey(): void
    {
        $config = new RedisRateLimitConfig(RedisTestSupport::keyPrefix('scripts'));
        $redis = RedisTestSupport::phpRedis();
        $redis->hMSet($config->globalKey(), [
            'tokens' => '1',
            'ts' => '99999999999999',
        ]);
        $redis->hMSet($config->laneKey('b'), [
            'tokens' => '0.5',
            'ts' => '99999999999999',
        ]);
        $script = LuaScript::giveBack();

        $written = (new PhpRedisScriptRunner($redis))->run(
            $script->sha1,
            $script->source,
            [$config->globalKey(), $config->laneKey('b')],
            // flag, global rate, global burst, N, unused a, unused b, lane b: position, rate, burst
            [1, '1.0', 10, 2, 3, 2, 2, '1.0', 4],
        );

        self::assertSame(2, $written);
        self::assertSame('6', $redis->hGet($config->globalKey(), 'tokens'), 'the sum 5 goes to the global key');
        self::assertSame('2.5', $redis->hGet($config->laneKey('b'), 'tokens'), 'lane b gets its 2');
        self::assertSame('99999999999999', $redis->hGet($config->laneKey('b'), 'ts'), 'a later stored time stays');
    }

    /**
     * @param list<int> $wanted
     *
     * @return list<int>
     */
    private function acquireWithNoKey(array $wanted, int $total): array
    {
        $script = LuaScript::acquire();
        /** @var list<int> $grants */
        $grants = (new PhpRedisScriptRunner(RedisTestSupport::phpRedis()))->run(
            $script->sha1,
            $script->source,
            [],
            [0, $total, 0, 0, count($wanted), ...$wanted],
        );

        return $grants;
    }

    /**
     * @param list<int> $wanted
     * @param array<int, int> $laneBursts the burst of each lane with a bucket, by position from 0; each bucket starts full
     *
     * @return list<int>
     */
    private function acquireWithKeys(array $wanted, int $total, int $globalBurst, array $laneBursts): array
    {
        $config = new RedisRateLimitConfig(RedisTestSupport::keyPrefix('scripts'));
        $keys = [$config->globalKey()];
        $triples = [];
        foreach ($laneBursts as $position => $burst) {
            $keys[] = $config->laneKey((string) $position);
            array_push($triples, $position + 1, '0.001', $burst);
        }
        $script = LuaScript::acquire();
        /** @var list<int> $grants */
        $grants = (new PhpRedisScriptRunner(RedisTestSupport::phpRedis()))->run(
            $script->sha1,
            $script->source,
            $keys,
            [1, $total, '0.001', $globalBurst, count($wanted), ...$wanted, ...$triples],
        );

        return $grants;
    }
}
