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
final readonly class MessageName
{
    public function __construct(
        public string $name,
    ) {
    }
}
