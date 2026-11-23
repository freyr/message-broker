<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/** A claimed delivery and the subscription of its lane: the input of {@see DeliverySender::send()}. */
final readonly class DeliveryPair
{
    public function __construct(
        public ClaimedDelivery $claimed,
        public Subscription $subscription,
    ) {}
}
