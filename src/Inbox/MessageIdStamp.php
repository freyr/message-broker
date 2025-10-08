<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Message ID Stamp.
 *
 * Tracks the message_id for deduplication and tracing.
 */
final readonly class MessageIdStamp implements StampInterface
{
    public function __construct(public string $messageId) {}
}
