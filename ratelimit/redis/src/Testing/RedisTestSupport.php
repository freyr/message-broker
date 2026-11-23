<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Testing;

use Predis\Client;
use Redis;

/**
 * The Redis set-up of the Redis adapter tests: the clients of each bridge,
 * built from the environment (REDIS_HOST, REDIS_PORT with the default 6379),
 * and a new key prefix for each limiter.
 *
 * The prefix holds the worker index of a parallel run (the environment
 * variable TEST_TOKEN that Infection sets) and a random part, so that two
 * workers, two tests and two limiters of one test never share a key. The
 * tests flush no database: the keys of a test expire with their time to
 * live.
 */
final class RedisTestSupport
{
    private static ?Redis $phpRedis = null;

    private static ?Client $predis = null;

    /** One connected phpredis client for the test process. */
    public static function phpRedis(): Redis
    {
        if (self::$phpRedis === null) {
            $redis = new Redis();
            $redis->connect(self::host(), self::port(), 2.0);
            self::$phpRedis = $redis;
        }

        return self::$phpRedis;
    }

    /** One Predis client for the test process, with the default options (errors throw). */
    public static function predis(): Client
    {
        return self::$predis ??= new Client([
            'host' => self::host(),
            'port' => self::port(),
        ]);
    }

    /**
     * A key prefix that no other limiter uses: <name>-t<token>-<random> in a
     * parallel run, <name>-<random> in a plain PHPUnit run.
     */
    public static function keyPrefix(string $name = 'test'): string
    {
        $token = getenv('TEST_TOKEN');
        $worker = is_string($token) && preg_match('/^\d+$/', $token) === 1 ? "-t{$token}" : '';

        return $name.$worker.'-'.bin2hex(random_bytes(6));
    }

    /**
     * Each key of a prefix, found with SCAN (a test helper; a deployment
     * never scans).
     *
     * @return list<string>
     */
    public static function keysOf(string $prefix): array
    {
        $redis = self::phpRedis();
        $keys = [];
        $cursor = null;
        do {
            $batch = $redis->scan($cursor, "{mb-rl:{$prefix}}:*", 1000);
            if (is_array($batch)) {
                array_push($keys, ...array_map(strval(...), $batch));
            }
        } while ($cursor !== 0 && $cursor !== null);
        sort($keys);

        return $keys;
    }

    private static function host(): string
    {
        $host = getenv('REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    private static function port(): int
    {
        $port = getenv('REDIS_PORT');

        return is_string($port) && $port !== '' ? (int) $port : 6379;
    }
}
