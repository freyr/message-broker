<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Attribute;
use ReflectionClass;

/**
 * MessageName Attribute.
 *
 * Marks domain events/commands with semantic names for messaging.
 * Format: {domain}.{subdomain}.{action}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class MessageName
{
    /** @var array<class-string, string|null> */
    private static array $cache = [];

    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * Extract the semantic message name from an object's #[MessageName] attribute.
     *
     * Returns null if the attribute is not present (caller decides the policy).
     * Results are cached in memory per class.
     */
    public static function fromClass(object $message): ?string
    {
        $class = $message::class;

        if (array_key_exists($class, self::$cache)) {
            return self::$cache[$class];
        }

        $reflection = new ReflectionClass($message);
        $attributes = $reflection->getAttributes(self::class);

        if ($attributes === []) {
            return self::$cache[$class] = null;
        }

        /** @var self $attribute */
        $attribute = $attributes[0]->newInstance();

        return self::$cache[$class] = $attribute->name;
    }
}
