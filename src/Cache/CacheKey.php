<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Cache;

/**
 * PSR-6 §Definitions: keys must not be empty and must not contain the
 * reserved characters {}()/\@: — pools throw InvalidCacheKey on violation.
 */
final class CacheKey
{
    private const string RESERVED = '{}()/\\@:';

    public static function validate(string $key): void
    {
        if ($key === '') {
            throw new InvalidCacheKey('Cache key must not be empty');
        }

        if (strpbrk($key, self::RESERVED) !== false) {
            throw new InvalidCacheKey("Cache key '{$key}' contains reserved characters ({}()/\\@:)");
        }
    }
}
