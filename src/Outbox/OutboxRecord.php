<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

/**
 * One row of the outbox_messages table — what the producer writes
 * and what a relay claims and publishes.
 */
final readonly class OutboxRecord
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public string $id,            // UUIDv7, from Message
        public string $lane,          // producer/relay seam
        public string $messageName,
        public string $key,           // transport-level key (Kafka partitioner, SQS FIFO group)
        public array $body,           // the full wire() two-section document
        public array $headers,        // transport-level headers
        public int $createdAt,        // epoch milliseconds
        public int $attempts = 0,
        public ?int $availableAt = null, // epoch ms; null = available now (insert path)
    ) {}
}
