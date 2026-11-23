<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis;

use RuntimeException;
use Throwable;

/**
 * An error reply of the server to a script call, for example OOM when Redis
 * is above maxmemory. A connection error is the exception of the client.
 */
final class RedisScriptError extends RuntimeException
{
    public static function fromReply(string $reply, ?Throwable $previous = null): self
    {
        return new self('The Redis script failed: '.$reply, previous: $previous);
    }
}
