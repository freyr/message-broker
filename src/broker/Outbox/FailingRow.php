<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

/**
 * One open outbox row with failed publish attempts, as OutboxStore::failing()
 * returns it for an operator. It holds the body size, never the body: the
 * body can hold personal data.
 */
final readonly class FailingRow
{
    public function __construct(
        public string $id,
        public string $lane,
        public string $messageName,
        public int $attempts,
        public int $createdAt,   // epoch ms
        public int $availableAt, // epoch ms, the next attempt
        public int $bodyBytes,
    ) {}
}
