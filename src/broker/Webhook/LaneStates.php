<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * The circuit breaker state of each lane, for the operator. The
 * implementation of {@see CircuitBreaker} implements this port too; the
 * delivery loop does not use it.
 */
interface LaneStates
{
    /**
     * Each lane that had a result, with its counters, in the order of the lane ID.
     *
     * @return list<LaneState>
     */
    public function all(): array;

    /**
     * Put the lane back into circulation, also a blocked lane: open_until and
     * the block become null, the window starts again from 0 at the present
     * time, and the openings in a row become 0. The opened count stays. It is
     * the only way to end a block.
     *
     * @return bool false when the lane has no state
     */
    public function close(string $lane): bool;
}
