<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * The global rate limit of the webhook requests: a token bucket that all
 * workers of one deployment share. Each request needs one permit, also a
 * retry. The bucket refills at the configured rate (requests each second)
 * and holds at most the burst capacity; the rate and the burst capacity are
 * the configuration of the implementation.
 *
 * {@see DeliveryLoop} calls acquire() with its batch size before each claim,
 * claims at most the grant, and gives the permits of the rows that it did not
 * claim back. A worker that stops between the grant and the requests loses
 * its permits: the rate is then lower for a moment, never higher.
 *
 * A database error propagates and stops the worker: the limit never fails open.
 * {@see NoRateLimit} limits nothing.
 */
interface RateLimiter
{
    /**
     * Take up to $wanted permits from the bucket.
     *
     * @param positive-int $wanted
     *
     * @return int<0, max> the granted permits, from 0 to $wanted
     */
    public function acquire(int $wanted): int;

    /**
     * Put unused permits back into the bucket, up to the burst capacity.
     *
     * @param positive-int $count
     */
    public function giveBack(int $count): void;
}
