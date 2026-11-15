<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

/**
 * One delivery of a message, as a transport hands it to the processor. It has
 * no transport type.
 */
final readonly class Delivery
{
    /**
     * @param array<string, mixed> $headers application headers, native PHP values
     * @param int $attempt 1 for the first delivery, then one more for each retry
     * @param string $source the queue (or stream) name
     * @param string|null $fallbackMessageId the message id of the transport, for the triage of a malformed message
     * @param int|null $deliveryCount the redelivery count of the transport itself, when it has one
     */
    public function __construct(
        public string $body,
        public array $headers,
        public int $attempt,
        public string $source,
        public ?string $fallbackMessageId = null,
        public ?int $deliveryCount = null,
    ) {}
}
