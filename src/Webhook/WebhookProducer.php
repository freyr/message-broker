<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use Freyr\MessageBroker\Message;
use Freyr\MessageBroker\Serializer\WireFormat;
use Symfony\Component\Uid\Uuid;

/**
 * The write side of the webhook queue. The application calls produce()
 * inside its own transaction; the producer opens none, so the delivery rows
 * commit or roll back with the business change.
 *
 * One row for each subscription that {@see SubscriptionProvider::forMessage()}
 * gives; a message with no subscription writes no row. Each row has its own
 * id and the message ID, and the body is the bytes that the wire format
 * encodes: the same bytes that {@see \Freyr\MessageBroker\Outbox\OutboxProducer}
 * stores for the message. The payload is encoded also when no subscription
 * exists, so a payload that cannot be encoded always fails at produce.
 */
final readonly class WebhookProducer
{
    public function __construct(
        private SubscriptionProvider $subscriptions,
        private DeliveryStore $store,
        private WireFormat $wireFormat,
    ) {}

    /** @param array<string, mixed> $headers */
    public function produce(Message $message, array $headers = []): void
    {
        $body = $this->wireFormat->encode($message->name, $message->payload());

        $this->store->insert(array_map(
            static fn (Subscription $subscription): DeliveryRecord => new DeliveryRecord(
                id: Uuid::v7()->toString(),
                messageId: $message->id,
                lane: $subscription->id,
                messageName: $message->name,
                body: $body,
                headers: $headers,
                createdAt: $message->createdAt,
            ),
            $this->subscriptions->forMessage($message->name),
        ));
    }
}
