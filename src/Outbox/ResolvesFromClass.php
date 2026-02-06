<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use ReflectionClass;

/**
 * Cached attribute resolution from class instances.
 *
 * Provides a static resolve() method that reads a PHP attribute from an
 * object's class via reflection and caches the result per class name.
 * Subsequent calls for the same class skip reflection entirely.
 *
 * Each using class gets its own static $cache (PHP trait semantics),
 * so there is no cross-contamination between attribute types.
 *
 * Usage: the using class must declare `private static array $cache = [];`
 * and call `self::resolve($message)?->name` (or whichever property).
 */
trait ResolvesFromClass
{
    /**
     * Resolve this attribute from the given object's class.
     *
     * Returns the attribute instance if present, null otherwise.
     * Results are cached in memory per class — reflection runs at most
     * once per class per process.
     */
    protected static function resolve(object $message): ?static
    {
        $class = $message::class;

        if (array_key_exists($class, self::$cache)) {
            return self::$cache[$class];
        }

        $reflection = new ReflectionClass($message);
        $attributes = $reflection->getAttributes(static::class);

        if ($attributes === []) {
            return self::$cache[$class] = null;
        }

        return self::$cache[$class] = $attributes[0]->newInstance();
    }
}
