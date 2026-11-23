<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis;

use InvalidArgumentException;

/**
 * The key prefix of one rate limit deployment: 1 to 64 characters of A-Z,
 * a-z, 0-9, "_", ".", ":" and "-". The keys are {mb-rl:<prefix>}:g (the
 * global bucket) and {mb-rl:<prefix>}:l:<lane> (the bucket of a lane).
 * Redis Cluster hashes only the text in the first braces, so all keys of one
 * deployment are in one slot, and one script can touch all of them. A brace
 * is not valid in the prefix, so a prefix cannot break the hash tag. Two
 * deployments on one Redis need two prefixes.
 */
final readonly class RedisRateLimitConfig
{
    private string $hashTag;

    public function __construct(
        public string $keyPrefix,
    ) {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $keyPrefix) !== 1) {
            throw new InvalidArgumentException('The key prefix must be 1 to 64 characters of A-Z, a-z, 0-9, "_", ".", ":" and "-", '.var_export($keyPrefix, true).' given');
        }
        $this->hashTag = "{mb-rl:{$keyPrefix}}";
    }

    /** The key of the global bucket. */
    public function globalKey(): string
    {
        return "{$this->hashTag}:g";
    }

    /** The key of the bucket of a lane. */
    public function laneKey(string $lane): string
    {
        return "{$this->hashTag}:l:{$lane}";
    }
}
