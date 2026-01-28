<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Routing;

use Attribute;

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
final readonly class AmqpRoutingKey
{
    public function __construct(
        public string $key,
    ) {}
}
