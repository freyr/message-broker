<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

/**
 * Consumer pipeline stage 2: the deserialized, transport-agnostic record.
 * Stage 3 (denormalization into the userland class) happens against
 * $payload; the envelope fields stay available to the handler alongside
 * the denormalized object.
 */
final readonly class IncomingMessage
{
    public function __construct(
        public string $messageId,
        public string $messageName,
        public int $createdAt,      // epoch milliseconds
        public array $payload,
        public array $headers = [],
    ) {}
}
