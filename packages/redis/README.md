# freyr/message-broker-redis

This package is the Redis rate limit store of the Freyr Message Broker
library. It is not published separately yet. Today the repository
`freyr/message-broker` holds its code in `src/adapter/redis/`.

The adapter holds `RedisRateLimiter`, which implements the port
`Webhook\RateLimiter` of the core in Redis. Each call is one atomic Lua
script with the clock of the Redis primary. Each token bucket is one HASH
with a time to live, and all keys of one deployment have one hash tag, so
the store also works in Redis Cluster. The application gives the Redis
client; the package opens no connection.

## Requirements

- PHP 8.4 or later
- `freyr/message-broker` ^0.14 (the core)
- Redis 7.0 or later, or Valkey 7.2 or later
- One Redis client: `ext-redis` (a `\Redis`, `\RedisCluster` or Relay
  client) or `predis/predis`

## Install

After the split:

```bash
composer require freyr/message-broker-redis
```

Today, `composer require freyr/message-broker` installs this code with the
core, and you add `ext-redis` or `predis/predis` yourself.

## Minimal wiring

```php
use Freyr\MessageBroker\Adapter\Redis\PhpRedisScriptRunner;
use Freyr\MessageBroker\Adapter\Redis\RedisRateLimitConfig;
use Freyr\MessageBroker\Adapter\Redis\RedisRateLimiter;

$redis = new \Redis();
$redis->connect('redis', 6379);

$rateLimiter = new RedisRateLimiter(new PhpRedisScriptRunner($redis), new RedisRateLimitConfig('shop.prod'));
// Give $rateLimiter to Webhook\DeliveryLoop of the core.
```

With Predis, use `new PredisScriptRunner($client)`.

## Documents

- [Redis rate limit store](../../docs/redis/RATE_LIMIT.md): the client bridges, the key prefix and the hash tag, the server versions, `maxmemory-policy`, the script cache, the tests
- [Webhooks](../../docs/core/WEBHOOKS.md#rate-limit): the rate limit rules of the core
- [Webhook delivery storage](../../docs/postgresql/WEBHOOKS.md#flow-control-tables): the PostgreSQL store of the rate limit and the circuit breaker

## Tests

The tests need Redis and no PostgreSQL; see
[Tests](../../docs/redis/RATE_LIMIT.md#tests).
