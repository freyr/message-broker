<?php

declare(strict_types=1);

namespace Freyr\MessageBroker;

use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;

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
    ) {}

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

        // Structural check: the wire document must serialize.
        // TODO: per-lane serializer conformance (e.g. Avro schema) in slice 5.
        json_encode($message->wire(), JSON_THROW_ON_ERROR);
    }
}
