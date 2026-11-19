<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * The circuit breaker of the webhook lanes, with one state for each lane that
 * all workers share. A lane leaves circulation for the open time of
 * {@see CircuitBreakerPolicy} when the error rate of its count window reaches
 * the threshold; after the open time it returns with a new window. There is
 * no probe state. While a lane is out of circulation, no worker claims its
 * rows, so its deliveries use no attempt.
 *
 * {@see DeliveryLoop} reads openLanes() before each claim and gives the
 * counts of each batch to record(). The loop makes the counts from the
 * results ({@see CircuitBreakerPolicy::countsAsFailure()}), so an
 * implementation does not know what a failure is.
 *
 * A database error propagates and stops the worker: the breaker never fails
 * open. {@see NoCircuitBreaker} opens no lane.
 */
interface CircuitBreaker
{
    /**
     * The lanes that are out of circulation at the present time: open_until
     * is later than the present time.
     *
     * @return list<string>
     */
    public function openLanes(): array;

    /**
     * Add the counts of one batch to the windows of their lanes, in one write
     * that takes the lanes in the order of the lane ID, so that two workers
     * with the same lanes never wait for each other in a circle. For each lane:
     *
     *  - while open_until is later than the present time, the counts are
     *    ignored and the lane never trips;
     *  - a window older than the window length, and the first window after
     *    open_until, start again from 0 before the counts are added;
     *  - when the window then has the minimum number of requests or more and
     *    the error rate or more ({@see CircuitBreakerPolicy::trips()}), the
     *    lane trips: open_until is the present time plus the open time, the
     *    window starts again from 0, and the opened count grows by 1.
     *
     * @param list<LaneCounts> $counts each lane at most one time
     *
     * @return LaneTransitions the lanes that this call took out of circulation,
     *                         and the lanes whose first window after open_until
     *                         this call started
     */
    public function record(array $counts): LaneTransitions;
}
