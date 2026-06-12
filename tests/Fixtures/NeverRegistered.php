<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Message;

/**
 * Has a committed local schema (reuses order_placed.avsc) but is never
 * registered with the registry — exercises the relay's behavior when the
 * out-of-band CI registration step was missed.
 */
final class NeverRegistered extends Message
{
    public static function create(): self
    {
        return new self(
            key: 'o-x',
            name: 'order.never_registered',
            payload: [
                'order_id' => 'o-x',
                'total_cents' => 1,
            ],
        );
    }
}
