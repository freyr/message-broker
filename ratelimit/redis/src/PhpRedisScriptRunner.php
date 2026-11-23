<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis;

use Redis;
use RedisCluster;
use Relay\Relay;

/**
 * The bridge for a phpredis client (\Redis, \RedisCluster) or a Relay client,
 * which have the same API for scripts. evalSha() returns false on an error
 * reply and keeps the message for getLastError(); the bridge clears the last
 * error before each call, so an error of an earlier command is not read as
 * the error of this call. On NOSCRIPT it calls eval() with the script. Each
 * other error throws {@see RedisScriptError}.
 *
 * \RedisCluster routes the call by its first key; all keys of one rate limit
 * deployment have one hash tag, so they are in one slot.
 */
final readonly class PhpRedisScriptRunner implements RedisScriptRunner
{
    public function __construct(
        private Redis|RedisCluster|Relay $client,
    ) {}

    public function run(string $sha1, string $script, array $keys, array $args): mixed
    {
        $arguments = [...$keys, ...$args];
        $this->client->clearLastError();
        $result = $this->client->evalsha($sha1, $arguments, count($keys));
        if ($result === false) {
            $error = $this->client->getLastError();
            if ($error === null) {
                return false;
            }
            if (!str_starts_with($error, 'NOSCRIPT')) {
                throw RedisScriptError::fromReply($error);
            }
            $this->client->clearLastError();
            $result = $this->client->eval($script, $arguments, count($keys));
            if ($result === false) {
                $error = $this->client->getLastError();
                if ($error !== null) {
                    throw RedisScriptError::fromReply($error);
                }
            }
        }

        return $result;
    }
}
