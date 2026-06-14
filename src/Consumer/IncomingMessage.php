<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

/**
 * Consumer pipeline stage 2: the deserialized, transport-agnostic record.
 * This is the hand-off record passed to MessageDispatcher::dispatch();
 * denormalizing $payload into a userland object (and routing it) is the
 * job of a separate component, not the broker.
 */
final readonly class IncomingMessage
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public string $messageId,
        public string $messageName,
        public int $createdAt,      // epoch milliseconds
        public array $payload,
        public array $headers = [],
    ) {}
}
