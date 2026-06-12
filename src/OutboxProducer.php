<?php

declare(strict_types=1);

namespace Freyr\MessageBroker;

use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\WireValidator;

/**
 * Shared outbox producer — the single write-side entry point.
 *
 * Writes through the APPLICATION's PDO connection (inside OutboxStore), so the
 * outbox row commits or rolls back atomically with the application's own state
 * change. No middleware, no transaction orchestration by the library.
 *
 * A lane is a named drain of the outbox table: one producer instance writes to
 * one lane; exactly one relay process serves one lane on one transport, which
 * is what guarantees total in-order publishing per lane.
 */
final readonly class OutboxProducer
{
    public function __construct(
        private OutboxStore $store,
        private string $lane = 'default',
        private ?WireValidator $validator = null,
    ) {}

    /** @param array<string, mixed> $headers */
    public function produce(Message $message, array $headers = []): void
    {
        // Poison prevention at the door (D17): a row that reaches the outbox
        // is publishable by definition — the relay never dead-letters.
        // Throws inside the application's transaction; nothing is committed.
        $this->assertPublishable($message);

        $this->store->insert(new OutboxRecord(
            id: $message->id,
            lane: $this->lane,
            messageName: $message->name,
            key: $message->key,
            body: $message->wire(),
            headers: $headers,
            createdAt: $message->createdAt,
        ));
    }

    private function assertPublishable(Message $message): void
    {
        if ($message->key === '' || $message->name === '') {
            throw new \InvalidArgumentException('Message key and name must be non-empty');
        }

        $wire = $message->wire();

        // Structural check: the wire document must serialize.
        json_encode($wire, JSON_THROW_ON_ERROR);

        // Per-lane serializer conformance (D17 poison prevention, spec A3):
        // e.g. AvroWireValidator on Avro lanes — local schema, no registry.
        $this->validator?->assertPublishable($wire);
    }
}
