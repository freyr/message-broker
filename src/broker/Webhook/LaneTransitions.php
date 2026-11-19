<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/** What one {@see CircuitBreaker::record()} call changed: the lanes that left circulation and the lanes that returned. */
final readonly class LaneTransitions
{
    /**
     * @param list<string> $left the lanes that this call took out of circulation
     * @param list<string> $returned the lanes whose first window after open_until this call started
     */
    public function __construct(
        public array $left = [],
        public array $returned = [],
    ) {}
}
