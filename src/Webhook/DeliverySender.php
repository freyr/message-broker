<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * Sends webhook deliveries to their subscriptions. The HTTP adapter
 * implements it; the test kit has a fake.
 *
 * Rules for implementations:
 *  - send() returns one result for each delivery, keyed by the id of the
 *    delivery row.
 *  - send() does not throw for a failed request: a timeout, a transport
 *    error or a bad status is a failed or refused result. It throws only for
 *    an error in its own code or configuration; the delivery loop then
 *    stops.
 *  - A result text never holds the URL or a secret.
 *  - Each request of one delivery carries the same message ID, so the
 *    receiver can remove a duplicate.
 */
interface DeliverySender
{
    /**
     * @param non-empty-list<DeliveryPair> $pairs
     *
     * @return array<string, DeliveryResult> delivery row id => result, one entry for each pair
     */
    public function send(array $pairs): array;

    /**
     * The longest time of one send() call, in milliseconds. The delivery
     * loop refuses to start when its lease is not longer.
     */
    public function maxSendMs(): int;
}
