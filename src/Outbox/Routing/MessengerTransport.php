<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

use Attribute;
use ReflectionClass;

/**
 * Custom Symfony Messenger transport.
 *
 * As a message is originally dispatched to an outbox doctrine transport,
 * there is a need to specify the transport to which it should be routed.
 *
 * By default, all outbox messages are routed to the 'amqp' transport.
 * It can be overridden with this attribute.
 *
 * Example:
 * ```php
 * #[MessageName('order.placed')]
 * #[MessengerTransport('commerce.events')]
 * final readonly class OrderPlaced { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class MessengerTransport
{
    /** @var array<class-string, string|null> */
    private static array $cache = [];

    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * Extract the transport name from an object's #[MessengerTransport] attribute.
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

        return self::$cache[$class] = $attribute->name;
    }
}
