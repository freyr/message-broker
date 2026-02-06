<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Attribute;

/**
 * MessageName Attribute.
 *
 * Marks domain events/commands with semantic names for messaging.
 * Format: {domain}.{subdomain}.{action}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class MessageName
{
    use ResolvesFromClass;

    /** @var array<class-string, static|null> */
    private static array $cache = [];

    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * Extract the semantic message name from an object's #[MessageName] attribute.
     *
     * Returns null if the attribute is not present (caller decides the policy).
     */
    public static function fromClass(object $message): ?string
    {
        return self::resolve($message)?->name;
    }
}
