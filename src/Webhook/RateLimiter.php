<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * The rate limit of the webhook requests, with two levels of token buckets
 * that all workers of one deployment share: one global bucket, and one bucket
 * for each lane with a limit. Each request needs a permit of both, also a
 * retry. A bucket refills at its rate up to its burst capacity, and a new
 * bucket starts full. The rates are parameters of each call
 * ({@see RateLimits}); the store holds no rate and no burst.
 *
 * One acquire() takes the permits of all lanes of one pass. The availability
 * of a lane is min(wanted, floor(lane tokens)), or the wanted count for a
 * lane with no limit. The global grant is min($total, floor(global tokens)),
 * or $total with no global bucket. {@see PermitDivision::divide()} divides
 * the global grant between the lanes, max-min fair, in the order of the call.
 *
 * The keys of each map are lane IDs. PHP turns a numeric key into an integer,
 * so an implementation reads each key as a string.
 *
 * A worker that stops between the grant and the requests loses its permits:
 * the rate is then lower for a moment, never higher. A store error propagates
 * and stops the worker: the limit never fails open. {@see NoRateLimit} has no
 * bucket.
 */
interface RateLimiter
{
    /**
     * Take the permits of the lanes of one pass: the refill of each bucket of
     * the call, the division, and the subtraction of each grant from its lane
     * bucket and of their sum from the global bucket, as one atomic step.
     *
     * @param array<array-key, positive-int> $wanted the wanted count of each lane, in the order of the call
     * @param int $total the cap of the sum of the grants, 0 or more
     *
     * @return array<array-key, int> the grant of each lane of $wanted, from 0 to its wanted count, in the order of the call
     */
    public function acquire(array $wanted, int $total, RateLimits $limits): array;

    /**
     * Put unused permits back: each count into its lane bucket, and their sum
     * into the global bucket, each up to its burst capacity.
     *
     * @param array<array-key, positive-int> $unused the unused count of each lane
     */
    public function giveBack(array $unused, RateLimits $limits): void;
}
