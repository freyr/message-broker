<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox\Message;

/**
 * Inbox Event Message.
 *
 * Wrapper for events received from AMQP and dispatched to inbox transport.
 */
final readonly class InboxEventMessage
{
    /**
     * @param string $messageName Format: domain.subdomain.action (e.g., order.placed)
     * @param array<string, mixed> $payload Event data
     * @param string $messageId Unique message identifier for deduplication
     * @param string $sourceQueue AMQP queue name
     */
    public function __construct(
        public string $messageName,
        public array $payload,
        public string $messageId,
        public string $sourceQueue,
    ) {
    }

    public function getMessageName(): string
    {
        return $this->messageName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getSourceQueue(): string
    {
        return $this->sourceQueue;
    }
}
