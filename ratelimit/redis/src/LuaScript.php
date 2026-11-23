<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis;

use LogicException;

/**
 * One Lua script of the adapter (a file in Lua/) and its SHA1 for EVALSHA.
 * The scripts have no shebang and use no FUNCTION, so the single-slot rule
 * of Redis Cluster applies to them.
 *
 * @internal
 */
final readonly class LuaScript
{
    public string $sha1;

    public function __construct(
        public string $source,
    ) {
        $this->sha1 = sha1($source);
    }

    /** The script of RedisRateLimiter::acquire(). */
    public static function acquire(): self
    {
        return self::fromFile(__DIR__.'/Lua/acquire.lua');
    }

    /** The script of RedisRateLimiter::giveBack(). */
    public static function giveBack(): self
    {
        return self::fromFile(__DIR__.'/Lua/give-back.lua');
    }

    public static function fromFile(string $path): self
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new LogicException("The Lua script {$path} cannot be read.");
        }

        return new self($source);
    }
}
