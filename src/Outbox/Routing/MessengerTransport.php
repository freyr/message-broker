<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

use Attribute;

/**
 * Custom Symfony Messenger transport
 *
 * As a message is originally dispatched to an outbox doctrine transport,
 * we need to specify the transport to which it should be routed.
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
final readonly class MessengerTransport
{
    public function __construct(
        public string $name,
    ) {
    }
}
