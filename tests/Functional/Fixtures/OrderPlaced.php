<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Fixtures;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\OutboxMessage;

/**
 * Test event for functional testing with multiple value objects.
 *
 * Complex event with various property types to test serialization.
 * No messageId property - MessageIdStampMiddleware generates it at dispatch time.
 */
#[MessageName('test.order.placed')]
final readonly class OrderPlaced implements OutboxMessage
{
    public function __construct(
        public Id $orderId,
        public Id $customerId,
        public float $totalAmount,
        public CarbonImmutable $placedAt,
    ) {}
}
