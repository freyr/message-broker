<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

use Attribute;
use ReflectionClass;

/**
 * AMQP Routing Key Attribute.
 *
 * Override the default AMQP routing key for a domain event.
 *
 * By default, the routing key is the full message name:
 * - order.placed → order.placed
 * - sla.calculation.started → sla.calculation.started
 *
 * Use this attribute to specify a custom routing key.
 *
 * Example:
 * ```php
 * #[MessageName('user.premium.custom')]
 * final readonly class UserPremiumUpgraded { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AmqpRoutingKey
{
    /** @var array<class-string, string|null> */
    private static array $cache = [];

    public function __construct(
        public readonly string $key,
    ) {}

    /**
     * Extract the routing key from an object's #[AmqpRoutingKey] attribute.
     *
     * Returns null if the attribute is not present (caller should use default).
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

        return self::$cache[$class] = $attribute->key;
    }
}
