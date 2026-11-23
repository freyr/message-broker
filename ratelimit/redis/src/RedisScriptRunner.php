<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis;

/**
 * Runs a Lua script on the Redis client of the application: EVALSHA with the
 * SHA1 of the script, and EVAL of the script when the server replies
 * NOSCRIPT (an empty script cache after a restart, a failover or SCRIPT
 * FLUSH). The package has one bridge for each client type:
 * {@see PhpRedisScriptRunner} (phpredis \Redis, \RedisCluster, Relay) and
 * {@see PredisScriptRunner} (Predis). The application builds and configures
 * the client (Cluster, Sentinel, TLS, auth); the package opens no connection.
 */
interface RedisScriptRunner
{
    /**
     * @param string $sha1 the SHA1 of $script, lower-case hexadecimal
     * @param list<string> $keys the KEYS of the script
     * @param list<string|int> $args the ARGV of the script
     *
     * @throws RedisScriptError for each error reply other than NOSCRIPT of the EVALSHA
     *
     * @return mixed the reply of the script, as the client gives it
     */
    public function run(string $sha1, string $script, array $keys, array $args): mixed;
}
