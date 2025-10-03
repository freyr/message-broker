<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures\Publisher;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\Routing\AmqpExchange;

/**
 * Test Domain Event with Custom Exchange - Publisher Side (Outbox).
 *
 * Demonstrates attribute-based routing override.
 */
#[MessageName('sla.calculation.started')]
#[AmqpExchange('sla.events')]  // Override: use custom exchange
final readonly class SlaCalculationStartedEvent
{
    public function __construct(
        public Id $messageId,
        public Id $slaId,
        public Id $ticketId,
        public CarbonImmutable $startedAt,
    ) {
    }
}
