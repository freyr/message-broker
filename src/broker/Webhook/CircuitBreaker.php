<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * The circuit breaker of the webhook lanes, with one state for each lane that
 * all workers share. A lane leaves circulation for the open time of
 * {@see CircuitBreakerPolicy} when the error rate of its count window reaches
 * the threshold; after the open time it returns with a new window. There is
 * no probe state. A trip when the lane already had the maximum number of
 * timed openings in a row blocks the lane: it stays out of circulation until
 * an operator closes it ({@see LaneStates::close()}). While a lane is out of
 * circulation, no worker claims its rows, so its deliveries use no attempt.
 *
 * The caller reads openLanes() before each claim and gives the counts of
 * each batch to record(). It makes the counts from the results with the
 * policy of the breaker ({@see CircuitBreakerPolicy::countsAsFailure()}), so
 * an implementation does not know what a failure is.
 *
 * A database error propagates and stops the worker: the breaker never fails
 * open. {@see NoCircuitBreaker} opens no lane.
 */
interface CircuitBreaker
{
    /**
     * The lanes that are out of circulation at the present time: open_until
     * is later than the present time, or the lane is blocked.
     *
     * @return list<string>
     */
    public function openLanes(): array;

    /**
     * Add the counts of one batch to the windows of their lanes, in one write
     * that takes the lanes in the order of the lane ID, so that two workers
     * with the same lanes never wait for each other in a circle. For each lane:
     *
     *  - while the lane is blocked, or open_until is later than the present
     *    time, the counts are ignored and the lane never trips;
     *  - a window older than the window length, and the first window after
     *    open_until, start again from 0 before the counts are added; a window
     *    older than the window length that has the minimum number of requests
     *    or more is a recovery: the openings in a row become 0;
     *  - when the window then has the minimum number of requests or more and
     *    the error rate or more ({@see CircuitBreakerPolicy::trips()}), the
     *    lane trips: the window starts again from 0, and the opened count and
     *    the openings in a row grow by 1. When the openings in a row before
     *    the trip already have the maximum ({@see CircuitBreakerPolicy::blocks()}),
     *    the trip blocks the lane; else open_until is the present time plus
     *    the open time.
     *
     * @param list<LaneCounts> $counts each lane at most one time
     *
     * @return LaneTransitions the lanes that this call took out of circulation
     *                         for the open time, the lanes whose first window
     *                         after open_until this call started, and the
     *                         lanes that this call blocked
     */
    public function record(array $counts): LaneTransitions;

    /** The policy of the breaker, so that the caller makes the counts with the same slow limit. */
    public function policy(): CircuitBreakerPolicy;
}
