<?php

declare(strict_types=1);

namespace Freyr\Messenger\Inbox\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Message Name Stamp.
 *
 * Tracks the original message_name from AMQP for routing and logging.
 */
final readonly class MessageNameStamp implements StampInterface
{
    public function __construct(
        public string $messageName,
    ) {}
}
