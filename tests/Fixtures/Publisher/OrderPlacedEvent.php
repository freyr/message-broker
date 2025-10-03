<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures\Publisher;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\MessageName;

/**
 * Test Domain Event - Publisher Side (Outbox).
 *
 * This event is dispatched in the publisher application and sent via outbox pattern.
 */
#[MessageName('order.placed')]
final readonly class OrderPlacedEvent
{
    public function __construct(
        public Id $messageId,
        public Id $orderId,
        public Id $customerId,
        public float $amount,
        public CarbonImmutable $placedAt,
    ) {
    }
}
