<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use RuntimeException;

/**
 * One row of the outbox_messages table — what the producer writes and what a
 * relay (or Debezium) publishes. `body` holds the FINAL wire bytes (payload
 * only, already encoded at produce time, E2). The envelope lives in the
 * `metadata` bag (E3): {message_name, message_id, created_at, …future}.
 * `message_name` is additionally MIRRORED to a real `message_name` column
 * (written by the store from messageName()) so a Debezium relay can map it to
 * an individual `x-message-name` header via stock EventRouter — the same
 * column/bag redundancy the design already uses for message_id (= id PK) and
 * created_at (= created_at column). The bag stays authoritative for the PHP
 * relay's explode and for migration-free extensibility.
 */
final readonly class OutboxRecord
{
    /**
     * @param array<string, mixed> $metadata envelope document (→ metadata column → individual x-message-* headers at relay)
     * @param array<string, mixed> $headers produce-time transport headers (e.g. correlation_id)
     */
    public function __construct(
        public string $id,                // UUIDv7, from Message
        public string $lane,              // producer/relay seam
        public string $key,               // transport-level key (message_key column)
        public array $metadata,
        public string $body,              // final wire bytes, payload only
        public array $headers,
        public int $createdAt,            // epoch milliseconds
        public int $attempts = 0,
        public ?int $availableAt = null,  // epoch ms; null = available now (insert path)
    ) {}

    /** message_name lives in the metadata bag — the relay reads it for routing. */
    public function messageName(): string
    {
        $name = $this->metadata['message_name'] ?? null;
        if (!is_string($name)) {
            throw new RuntimeException("Outbox record {$this->id} metadata has no string message_name");
        }

        return $name;
    }
}
