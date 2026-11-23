<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * A delivery row that a claim leased. The lease is the new available_at of
 * the row (epoch milliseconds), which the claim wrote and committed.
 * {@see DeliveryStore::complete()} changes the row only while its
 * available_at is still equal to this value (the lease check).
 */
final readonly class ClaimedDelivery
{
    public function __construct(
        public DeliveryRecord $delivery,
        public int $lease,
    ) {}
}
