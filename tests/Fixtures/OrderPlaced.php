<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Message;

final class OrderPlaced extends Message
{
    public static function create(string $orderId, int $totalCents): self
    {
        return new self(
            key: $orderId,
            name: 'order.placed',
            payload: [
                'order_id' => $orderId,
                'total_cents' => $totalCents,
            ],
        );
    }
}
