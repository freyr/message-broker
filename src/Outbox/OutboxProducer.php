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
        private ?LaneLimits $limits = null,
    ) {}

    /** @param array<string, mixed> $headers */
    public function produce(Message $message, array $headers = []): void
    {
        // Encode at the door: the real encode IS the conformance check. A
        // non-publishable payload throws here (JsonException) inside the
        // application's transaction — nothing
        // commits, and the bytes that were validated are the bytes stored.
        $body = $this->wireFormat->encode($message->name, $message->payload());

        if ($this->limits !== null) {
            $this->assertWithinLimits($message, $body, $headers, $this->limits);
        }

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

    /**
     * The body size is the length of the encoded bytes; the header size is the length of the
     * JSON form that the store writes. A size equal to the limit passes.
     *
     * @param array<string, mixed> $headers
     */
    private function assertWithinLimits(Message $message, string $body, array $headers, LaneLimits $limits): void
    {
        if ($limits->maxBodyBytes !== null && strlen($body) > $limits->maxBodyBytes) {
            throw $this->tooLarge($message, MessageTooLarge::PART_BODY, strlen($body), $limits->maxBodyBytes);
        }
        if ($limits->maxHeaderBytes !== null) {
            $size = strlen(json_encode($headers, JSON_THROW_ON_ERROR));
            if ($size > $limits->maxHeaderBytes) {
                throw $this->tooLarge($message, MessageTooLarge::PART_HEADERS, $size, $limits->maxHeaderBytes);
            }
        }
    }

    /** @param MessageTooLarge::PART_* $part */
    private function tooLarge(Message $message, string $part, int $size, int $limit): MessageTooLarge
    {
        return new MessageTooLarge($this->lane, $message->id, $message->name, $part, $size, $limit);
    }
}
