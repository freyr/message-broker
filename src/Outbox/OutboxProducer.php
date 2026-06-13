<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Message;
use Freyr\MessageBroker\Serializer\WireFormat;
use InvalidArgumentException;

/**
 * Shared outbox producer — the single write-side entry point.
 *
 * Writes through the APPLICATION's PDO connection (inside OutboxStore), so the
 * outbox row commits or rolls back atomically with the application's own state
 * change. The wire format encodes the payload at produce time (E2): the row's
 * `body` holds the final wire bytes, the `metadata` column holds the envelope.
 *
 * A lane is a named drain of the outbox table: one producer instance writes to
 * one lane; exactly one relay process serves one lane on one transport, which
 * guarantees total in-order publishing per lane.
 */
final readonly class OutboxProducer
{
    public function __construct(
        private OutboxStore $store,
        private WireFormat $wireFormat,
        private string $lane = 'default',
    ) {}

    /** @param array<string, mixed> $headers */
    public function produce(Message $message, array $headers = []): void
    {
        if ($message->key === '' || $message->name === '') {
            throw new InvalidArgumentException('Message key and name must be non-empty');
        }

        $wire = $message->wire();

        // Encode at the door (E5/D17): the real encode IS the conformance
        // check. A non-publishable payload throws here (JsonException /
        // AvroIOTypeException) inside the application's transaction — nothing
        // commits, and the bytes that were validated are the bytes stored.
        $body = $this->wireFormat->encode($message->name, $wire['payload']);

        $this->store->insert(new OutboxRecord(
            id: $message->id,
            lane: $this->lane,
            key: $message->key,
            metadata: $wire['metadata'],
            body: $body,
            headers: $headers,
            createdAt: $message->createdAt,
        ));
    }
}
