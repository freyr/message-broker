<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * The webhook subscriptions of the application. The application owns them
 * and implements this port; the library has no subscription table.
 */
interface SubscriptionProvider
{
    /**
     * The subscriptions that receive a message of this name; an empty list
     * when none does. {@see WebhookProducer} calls it inside the transaction
     * of the application, so it must be fast.
     *
     * @return list<Subscription>
     */
    public function forMessage(string $messageName): array;

    /**
     * The subscription whose id is $lane, or null when the application has
     * none (for example a removed subscription). The delivery loop calls it
     * at send time for each lane of a claim.
     */
    public function find(string $lane): ?Subscription;

    /**
     * The ids of all subscriptions: the lanes that the delivery loop claims.
     * The loop reads it at its refresh interval.
     *
     * @return list<string>
     */
    public function lanes(): array;
}
