<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

use Attribute;
use Freyr\MessageBroker\Outbox\ResolvesFromClass;

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
    use ResolvesFromClass;

    /** @var array<class-string, static|null> */
    private static array $cache = [];

    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * Extract the routing key from an object's #[AmqpRoutingKey] attribute.
     *
     * Returns null if the attribute is not present (caller should use default).
     */
    public static function fromClass(object $message): ?string
    {
        return self::resolve($message)?->name;
    }
}
