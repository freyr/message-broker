# Redis rate limit store

The Redis adapter (package folder `ratelimit/redis/`, namespace
`Freyr\MessageBroker\RateLimit\Redis\`,
package `freyr/message-broker-ratelimit-redis`) implements the `Webhook\RateLimiter` port of the
core in Redis: `RedisRateLimiter`. The rules of the port (the two levels of
buckets, the max-min division, the give-back) are in
[docs/WEBHOOKS.md](https://github.com/freyr/message-broker/blob/main/docs/WEBHOOKS.md#rate-limit). The circuit breaker
and the delivery store stay in PostgreSQL.

A deployment uses one rate limit store for all its workers:
`PostgreSqlRateLimiter` or `RedisRateLimiter`. Workers on two stores have two
limits.

## Wiring

The application builds the Redis client (Cluster, Sentinel, TLS, auth) and
gives it to a bridge. The package opens no connection and holds no
credential.

```php
use Freyr\MessageBroker\RateLimit\Redis\PhpRedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\PredisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimitConfig;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimiter;

// phpredis (ext-redis): a \Redis, a \RedisCluster or a Relay client
$redis = new \Redis();
$redis->connect('redis', 6379);
$rateLimiter = new RedisRateLimiter(new PhpRedisScriptRunner($redis), new RedisRateLimitConfig('shop.prod'));

// or Predis (predis/predis)
$rateLimiter = new RedisRateLimiter(
    new PredisScriptRunner(new \Predis\Client('tcp://redis:6379')),
    new RedisRateLimitConfig('shop.prod'),
);

// Give $rateLimiter to Webhook\DeliveryLoop as rateLimiter.
```

The package requires no Redis client. Install one:

| Client | Bridge | Install |
|---|---|---|
| phpredis `\Redis`, `\RedisCluster` | `PhpRedisScriptRunner` | `ext-redis` |
| Relay | `PhpRedisScriptRunner` | `ext-relay` |
| Predis | `PredisScriptRunner` | `composer require predis/predis` |

## The client bridges

`RedisScriptRunner::run(string $sha1, string $script, list<string> $keys, list<string|int> $args): mixed`
runs one script: `EVALSHA` with the SHA1 of the script, and `EVAL` of the
script when the server replies `NOSCRIPT`.

- **`PhpRedisScriptRunner`** (`\Redis`, `\RedisCluster`, Relay): `evalSha()`
  returns `false` on an error reply, and the client keeps the message for
  `getLastError()`. The bridge clears the last error before each call, so the
  error of an earlier command is not read as the error of this call. On
  `NOSCRIPT` it clears the error and calls `eval()`.
- **`PredisScriptRunner`** (`Predis\ClientInterface`): with the default
  options, Predis throws `Predis\Response\ServerException` for an error
  reply. With the option `exceptions` off, it returns a
  `Predis\Response\Error`. The bridge handles both. On `NOSCRIPT` it calls
  `eval`.

Each other error reply throws `RedisScriptError`, with the reply text in the
message. A connection error is the exception of the client. Both stop the
worker: the limit never fails open.

The CI tests `\Redis` and Predis. `\RedisCluster` and Relay are not tested:
the adapter expects that `\RedisCluster` routes `evalSha()` by the first key,
and that Relay has the phpredis API for `evalSha()`, `eval()`,
`getLastError()` and `clearLastError()`, with errors returned as `false`.

## The key prefix and the hash tag

`RedisRateLimitConfig` holds the key prefix of the deployment: 1 to 64
characters of `A-Z`, `a-z`, `0-9`, `_`, `.`, `:` and `-`. Two deployments on
one Redis need two prefixes.

| Key | Type | Fields |
|---|---|---|
| `{mb-rl:<prefix>}:g` | HASH | `tokens` (a decimal number as a string), `ts` (milliseconds of the Redis clock) |
| `{mb-rl:<prefix>}:l:<lane>` | HASH | the same fields, for one lane with a limit |

- **The hash tag.** Redis Cluster hashes only the text in the first `{...}`,
  so all keys of one deployment are in one slot, and one script can touch the
  global key and the lane keys. A brace is not valid in the prefix, so a
  prefix cannot break the hash tag. All limiter keys of one deployment are on
  one shard.
- **The keys hold no rate.** The rates and the burst capacities come from
  each call, as in the PostgreSQL store. A lane with no limit has no key.
- **Time to live.** Each write sets `PEXPIRE` to the time of a full refill
  plus 1 second: `ceil(burst * 1000 / rate) + 1000` ms. An absent key is a
  full bucket, so an expired key and a full bucket give the same grant, and
  an expiry loses no state. The keys of a removed lane, or of a lane that
  lost its limit, expire with no clean-up job.
- A key holds no message data, no URL and no secret. The key name holds the
  lane ID, so a shared Redis with other tenants sees the lane IDs.
- No migration: an absent key is a full bucket.

## The scripts

The adapter has two Lua scripts, `Lua/acquire.lua` and `Lua/give-back.lua`.
`RedisRateLimiter` reads each file one time, when it is constructed. Each
call is one script: atomic, with the clock of the Redis primary
(`redis.call('TIME')`).

1. The script reads `tokens` and `ts` of each key of the call. An absent key
   starts full. The refill is `min(burst, tokens + max(0, now - ts) * rate / 1000)`.
   A Redis time earlier than `ts` gives a refill of 0 and keeps `ts`.
2. The acquire script divides the global grant max-min, with the algorithm of
   `Webhook\PermitDivision` of the core, on whole numbers. A test runs the
   script and `PermitDivision::divide()` with the fixed vectors of the spec
   and with 150 seeded vectors, and gets the same grants.
3. It subtracts the grants, writes `HSET tokens ts` and `PEXPIRE` for each key
   that it read, and returns one whole grant for each lane, in the order of
   the call. The give-back script adds the unused counts, each up to its
   burst capacity.

`tokens` keeps 17 significant digits, so the stored value reads back as the
same double. The scripts have no `#!lua` shebang and use no `FUNCTION`.

**The argument layout.** `KEYS` has the global key first (only with a global
rate), then the key of each lane with a limit, in the order of the call.
`ARGV` starts with the flag "global key present" (`1` or `0`), then the total
(the acquire only), the global rate and burst (`0` and `0` with no global
rate), the number of lanes N, the N wanted (or unused) counts, and for each
lane key: the position of its lane in the call (from 1), its rate and its
burst. A rate goes with all its digits (`var_export()`).

## Server versions

Redis 7.0 or later, or Valkey 7.2 or later. Both have effects replication
only, so `TIME` before a write is safe with no extra call. The CI runs the
suite `ratelimit-redis` against `redis:7-alpine` and `valkey/valkey:8-alpine`. Redis
versions before 7.0 are not supported.

## The script cache

A restart, a failover or `SCRIPT FLUSH` empties the script cache of the
server. The next call then gets `NOSCRIPT`, the bridge sends `EVAL` with the
script, and the call succeeds. `EVAL` loads the script into the cache again,
so the call after it is an `EVALSHA` again.

## `maxmemory-policy`

- **`noeviction`**: when Redis is above `maxmemory`, the script fails with an
  `OOM` error reply, and the call throws `RedisScriptError`. When a write
  fails after other writes of the same script, those writes stay: some lanes
  can lose permits, so the rate is lower, never higher. The worker stops,
  because the flow control never fails open.
- **A `volatile-*` or `allkeys-*` policy**: Redis can evict a bucket key. An
  evicted key is a full bucket, so the rate can be at most one burst higher,
  one time for each eviction.

Each key has a time to live, so a `volatile-*` policy can evict them.

## Rollback

Change the deployment back to `PostgreSqlRateLimiter`. The PostgreSQL rows
start full or from their old state; a new store never grants more than one
burst at its start. The Redis keys expire with their time to live.

## Tests

The tests of the adapter are in its `tests/` folder (the PHPUnit suite
`ratelimit-redis`). They need Redis and no PostgreSQL. The compose service `redis` is in
`depends_on` of `php`, and the tests find it through `REDIS_HOST` (and
`REDIS_PORT`, default 6379).

- `PhpRedisRateLimiterContractTest` and `PredisRateLimiterContractTest`
  extend `RateLimiterContractTestCase` of the core, one time for each bridge.
  Each limiter gets a new key prefix, so its buckets start full. Their
  `ageBuckets(ms)` runs `HINCRBY <key> ts -ms` on each key of the prefixes of
  the test; the time to live stays.
- `RedisRateLimiterTest` (functional): the keys and their time to live, no
  global key with a `null` global rate, a deleted key as a full bucket, a
  `SCRIPT FLUSH` before a call, a Redis time earlier than the stored time,
  the digits of the tokens, and an error reply of the script.
- `RateLimitScriptsTest`: the scripts against `PermitDivision::divide()`.
- Unit tests of each bridge (a `NOSCRIPT` reply gives one `EVAL`; each other
  error throws), of the argument layout, of the key prefix and of the script
  files.

`Testing\RedisTestSupport` builds the clients and gives a key prefix that
holds `TEST_TOKEN`, so parallel Infection workers do not share keys. The tests
flush no database. Infection does not mutate the Lua code; the contract cases
and the vector test test it.

To run the suite against Valkey, start a Valkey server and set `REDIS_HOST`
to it, for example `docker compose run --rm -e REDIS_HOST=<valkey host> php vendor/bin/phpunit --testsuite redis`.
