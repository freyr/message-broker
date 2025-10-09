<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Message Name Stamp.
 *
 * Tracks the original message_name of the message.
 */
final readonly class MessageNameStamp implements StampInterface
{
    public function __construct(
        public string $messageName,
    ) {
    }
}
