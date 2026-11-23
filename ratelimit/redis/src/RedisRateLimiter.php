<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis;

use Freyr\MessageBroker\Webhook\RateLimiter;
use Freyr\MessageBroker\Webhook\RateLimits;

/**
 * The token buckets of the RateLimiter port in Redis: one HASH for each
 * bucket (tokens and the time in milliseconds of the Redis clock), the key
 * {mb-rl:<prefix>}:g for the global bucket and {mb-rl:<prefix>}:l:<lane> for
 * a lane with a limit ({@see RedisRateLimitConfig}). The keys hold no rate;
 * the rates come from each call.
 *
 * Each call is one Lua script (Lua/acquire.lua, Lua/give-back.lua), atomic,
 * with the clock of the Redis primary (TIME): the refill, the max-min
 * division of {@see \Freyr\MessageBroker\Webhook\PermitDivision} and the
 * writes. An absent key is a full bucket, and each written key expires after
 * the time of a full refill plus 1 second, so an expiry loses no state.
 *
 * The scripts go through a {@see RedisScriptRunner}: EVALSHA, and EVAL when
 * the script cache is empty. Needs Redis 7.0 or later, or Valkey 7.2 or
 * later (effects replication). A connection error or an error reply (for
 * example OOM above maxmemory) propagates and stops the worker: the limit
 * never fails open.
 */
final readonly class RedisRateLimiter implements RateLimiter
{
    private LuaScript $acquireScript;

    private LuaScript $giveBackScript;

    public function __construct(
        private RedisScriptRunner $runner,
        private RedisRateLimitConfig $config,
    ) {
        $this->acquireScript = LuaScript::acquire();
        $this->giveBackScript = LuaScript::giveBack();
    }

    public function acquire(array $wanted, int $total, RateLimits $limits): array
    {
        /** @var list<int> $grants the script returns one whole grant for each lane, in the order of the call */
        $grants = $this->run($this->acquireScript, $wanted, [$total], $limits);

        // The keys of $wanted, also an integer key of a numeric lane ID, in the order of the call.
        return array_combine(array_keys($wanted), $grants);
    }

    public function giveBack(array $unused, RateLimits $limits): void
    {
        $this->run($this->giveBackScript, $unused, [], $limits);
    }

    /**
     * Run a script with the layout of the spec. KEYS: the global key (only
     * with a global rate), then the key of each lane with a limit, in the
     * order of the call. ARGV: the flag "global key present" (1 or 0), $head
     * (the total of an acquire), the global rate and burst (0 and 0 with no
     * global rate), N, the N counts, then for each lane key the position of
     * its lane (from 1), its rate and its burst.
     *
     * @param array<array-key, int> $counts the wanted or the unused count of each lane, in the order of the call
     * @param list<int> $head
     */
    private function run(LuaScript $script, array $counts, array $head, RateLimits $limits): mixed
    {
        $keys = [];
        $global = [0, 0, 0];
        if ($limits->global !== null) {
            $keys[] = $this->config->globalKey();
            $global = [1, self::number($limits->global->perSecond), $limits->global->burst];
        }
        $laneArgs = [];
        $position = 0;
        foreach ($counts as $lane => $count) {
            ++$position;
            $rate = $limits->forLane((string) $lane);
            if ($rate !== null) {
                $keys[] = $this->config->laneKey((string) $lane);
                array_push($laneArgs, $position, self::number($rate->perSecond), $rate->burst);
            }
        }
        [$flag, $globalRate, $globalBurst] = $global;

        return $this->runner->run(
            $script->sha1,
            $script->source,
            $keys,
            [$flag, ...$head, $globalRate, $globalBurst, count($counts), ...array_values($counts), ...$laneArgs],
        );
    }

    /** A rate with all its digits: var_export() gives the shortest text that reads back as the same float. */
    private static function number(float $value): string
    {
        return var_export($value, true);
    }
}
