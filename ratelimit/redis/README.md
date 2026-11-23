# freyr/message-broker-ratelimit-redis

This package is the Redis rate limit store of the Freyr Message Broker library,
namespace `Freyr\MessageBroker\RateLimit\Redis\`.

> The repository `freyr/message-broker-ratelimit-redis` is a read-only copy of the folder
> `ratelimit/redis/` of [freyr/message-broker](https://github.com/freyr/message-broker).
> Development, issues and pull requests are in freyr/message-broker. A
> release has the tag `ratelimit-redis-v<version>` in freyr/message-broker
> and the tag `v<version>` in `freyr/message-broker-ratelimit-redis`.

The adapter holds `RedisRateLimiter`, which implements the port
`Webhook\RateLimiter` of the core in Redis. Each call is one atomic Lua
script with the clock of the Redis primary. Each token bucket is one HASH
with a time to live, and all keys of one deployment have one hash tag, so
the store also works in Redis Cluster. The application gives the Redis
client; the package opens no connection.

## Requirements

- PHP 8.4 or later
- `freyr/message-broker` ^0.15 (the core)
- Redis 7.0 or later, or Valkey 7.2 or later
- One Redis client: `ext-redis` (a `\Redis`, `\RedisCluster` or Relay
  client) or `predis/predis`

## Install

```bash
composer require freyr/message-broker-ratelimit-redis
```

Add a Redis client yourself: `ext-redis` or `predis/predis`.

## Minimal wiring

```php
use Freyr\MessageBroker\RateLimit\Redis\PhpRedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimitConfig;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimiter;

$redis = new \Redis();
$redis->connect('redis', 6379);

$rateLimiter = new RedisRateLimiter(new PhpRedisScriptRunner($redis), new RedisRateLimitConfig('shop.prod'));
// Give $rateLimiter to Webhook\DeliveryLoop of the core.
```

With Predis, use `new PredisScriptRunner($client)`.

## Documents

- [Redis rate limit store](docs/RATE_LIMIT.md): the client bridges, the key prefix and the hash tag, the server versions, `maxmemory-policy`, the script cache, the tests
- [Webhooks](https://github.com/freyr/message-broker/blob/main/docs/WEBHOOKS.md#rate-limit): the rate limit rules of the core
- [Webhook delivery storage](https://github.com/freyr/message-broker/blob/main/storage/postgresql/docs/WEBHOOKS.md#flow-control-tables): the PostgreSQL store of the rate limit and the circuit breaker

## Tests

The tests need Redis and no PostgreSQL; see
[Tests](docs/RATE_LIMIT.md#tests).
