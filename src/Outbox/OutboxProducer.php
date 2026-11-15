<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Message;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Serializer\WireFormat;

/**
 * Shared outbox producer — the single write-side entry point.
 *
 * Writes through the APPLICATION's PDO connection (inside OutboxStore), so the
 * outbox row commits or rolls back atomically with the application's own state
 * change — provided the application opened that transaction; the producer
 * opens none. The wire format encodes the payload at produce time: the row's
 * `body` holds the final wire bytes.
 *
 * A lane is a named drain of the outbox table: one producer instance writes to
 * one lane; any number of relay workers drain it. There is no ordering
 * guarantee — consumers must be order-insensitive.
 */
final readonly class OutboxProducer
{
    public function __construct(
        private OutboxStore $store,
        private WireFormat $wireFormat,
        private string $lane = 'default',
        private ?BrokerEvents $events = null,
    ) {}

    /** @param array<string, mixed> $headers */
    public function produce(Message $message, array $headers = []): void
    {
        // Encode at the door: the real encode IS the conformance check. A
        // non-publishable payload throws here (JsonException) inside the
        // application's transaction — nothing
        // commits, and the bytes that were validated are the bytes stored.
        $body = $this->wireFormat->encode($message->name, $message->payload());

        // OutboxRecord rejects an empty or over-long lane or name.
        $this->store->insert(new OutboxRecord(
            id: $message->id,
            lane: $this->lane,
            messageName: $message->name,
            body: $body,
            headers: $headers,
            createdAt: $message->createdAt,
        ));

        $this->events?->record(BrokerEvents::PRODUCED, [
            'lane' => $this->lane,
            'message_id' => $message->id,
            'message_name' => $message->name,
        ]);
    }
}
