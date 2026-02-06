<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Attribute;
use ReflectionClass;
use RuntimeException;

/**
 * MessageName Attribute.
 *
 * Marks domain events/commands with semantic names for messaging.
 * Format: {domain}.{subdomain}.{action}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class MessageName
{
    /** @var array<class-string, string> */
    private static array $cache = [];

    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * Extract the semantic message name from an object's #[MessageName] attribute.
     *
     * Results are cached in memory keyed by class name, so reflection
     * is performed at most once per class per process.
     */
    public static function fromClass(object $message): string
    {
        $class = $message::class;

        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $reflection = new ReflectionClass($message);
        $attributes = $reflection->getAttributes(self::class);

        if ($attributes === []) {
            throw new RuntimeException(sprintf(
                'Class %s must have #[MessageName] attribute',
                $class,
            ));
        }

        /** @var self $attribute */
        $attribute = $attributes[0]->newInstance();

        return self::$cache[$class] = $attribute->name;
    }
}
