<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Source Queue Stamp.
 *
 * Tracks the original AMQP queue name from which the message was consumed.
 */
final readonly class SourceQueueStamp implements StampInterface
{
    public function __construct(
        public string $sourceQueue,
    ) {}
}
