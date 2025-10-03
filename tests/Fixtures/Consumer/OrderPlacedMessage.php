<?php

declare(strict_types=1);

namespace Freyr\Messenger\Tests\Fixtures\Consumer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;

/**
 * Test Message DTO - Consumer Side (Inbox).
 *
 * This message is deserialized from AMQP and processed via inbox pattern.
 * Message name: 'order.placed' (same as publisher event)
 * Class name: Different from publisher (OrderPlacedMessage vs OrderPlacedEvent)
 */
final readonly class OrderPlacedMessage
{
    public function __construct(
        public Id $orderId,
        public Id $customerId,
        public float $amount,
        public CarbonImmutable $placedAt,
    ) {
    }
}
