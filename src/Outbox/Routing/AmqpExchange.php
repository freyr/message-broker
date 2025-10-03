<?php

declare(strict_types=1);

namespace Freyr\Messenger\Outbox\Routing;

use Attribute;

/**
 * AMQP Exchange Attribute.
 *
 * Override the default AMQP exchange for a domain event.
 *
 * By default, the exchange is derived from the first 2 parts of the message name:
 * - order.placed → order.placed
 * - sla.calculation.started → sla.calculation
 *
 * Use this attribute to specify a custom exchange for special routing needs.
 *
 * Example:
 * ```php
 * #[MessageName('order.placed')]
 * #[AmqpExchange('commerce.events')]
 * final readonly class OrderPlaced { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AmqpExchange
{
    public function __construct(
        public string $name,
    ) {
    }
}
