<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Fixtures;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\OutboxMessage;
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\Routing\AmqpExchange;

/**
 * Test message routed to a custom AMQP exchange via #[AmqpExchange].
 *
 * Used to verify that the sender locator resolves the correct transport
 * when a message overrides the default exchange.
 */
#[MessageName('commerce.order.placed')]
#[AmqpExchange('commerce')]
final readonly class CommerceTestMessage implements OutboxMessage
{
    public function __construct(
        public Id $orderId,
        public float $amount,
        public CarbonImmutable $placedAt,
    ) {}
}
