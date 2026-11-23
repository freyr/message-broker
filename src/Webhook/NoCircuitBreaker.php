<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/** A {@see CircuitBreaker} that opens no lane and keeps no count. Its policy is the default policy. */
final class NoCircuitBreaker implements CircuitBreaker
{
    public function openLanes(): array
    {
        return [];
    }

    public function record(array $counts): LaneTransitions
    {
        return new LaneTransitions();
    }

    public function policy(): CircuitBreakerPolicy
    {
        return new CircuitBreakerPolicy();
    }
}
