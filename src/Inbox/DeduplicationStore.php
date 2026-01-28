<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox;

/**
 * Deduplication Store Interface.
 *
 * Handles persistence of processed message IDs for deduplication.
 */
interface DeduplicationStore
{
    /**
     * Check if a message has already been processed.
     *
     * If the message is new, it should be marked as processed atomically.
     * If the message is a duplicate, return true without modifying state.
     *
     * @param string $messageId Unique message identifier
     * @param string $messageName Message class name (for logging/queries)
     *
     * @return bool True if message is a duplicate (already processed), false if new
     */
    public function isDuplicate(string $messageId, string $messageName): bool;
}
