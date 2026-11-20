<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/** What one {@see CircuitBreaker::record()} call changed: the lanes that left circulation, returned, or were blocked. */
final readonly class LaneTransitions
{
    /**
     * @param list<string> $left the lanes that this call took out of circulation for the open time
     * @param list<string> $returned the lanes whose first window after open_until this call started
     * @param list<string> $blocked the lanes that this call blocked, in place of a timed opening
     */
    public function __construct(
        public array $left = [],
        public array $returned = [],
        public array $blocked = [],
    ) {}
}
