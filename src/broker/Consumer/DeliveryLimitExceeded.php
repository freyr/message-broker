<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

use RuntimeException;

/**
 * The error class of a dead letter that the delivery limit of the
 * DeliveryProcessor writes: the transport delivered the message again after
 * `$count` deliveries that ended with no ack (each a process stop), and the
 * count reached the limit. The handler did not run for this delivery.
 */
final class DeliveryLimitExceeded extends RuntimeException
{
    public static function atCount(int $count, int $limit): self
    {
        return new self("Delivery limit reached: {$count} deliveries ended with no ack (limit {$limit})");
    }
}
