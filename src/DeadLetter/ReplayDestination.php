<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

/**
 * Where a replay of {@see ReplayService} puts the message: an outbox lane
 * (the lane argument), or for a webhook dead letter the webhook delivery lane
 * of its source. The lane names alone cannot tell the two apart, so the
 * destination carries the kind.
 */
final readonly class ReplayDestination
{
    public function __construct(
        public string $lane,
        public bool $webhook,
    ) {}
}
