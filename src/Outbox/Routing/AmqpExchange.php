<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

use Attribute;
use Freyr\MessageBroker\Outbox\ResolvesFromClass;

/**
 * AMQP Exchange Attribute.
 *
 * Override the default AMQP exchange (Symfony transport) for a domain event.
 *
 * By default, all outbox messages are published via the 'amqp' transport.
 * Use this attribute to publish to a different transport (each configured
 * with its own AMQP exchange).
 *
 * The attribute value must match a transport name registered in the
 * OutboxToAmqpBridge sender locator.
 *
 * Example:
 * ```php
 * #[MessageName('order.placed')]
 * #[AmqpExchange('commerce')]
 * final readonly class OrderPlaced implements OutboxMessage { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AmqpExchange
{
    use ResolvesFromClass;

    /** @var array<class-string, static|null> */
    private static array $cache = [];

    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * Extract the exchange name from an object's #[AmqpExchange] attribute.
     *
     * Returns null if the attribute is not present (caller should use default).
     */
    public static function fromClass(object $message): ?string
    {
        return self::resolve($message)?->name;
    }
}
