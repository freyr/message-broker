<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Store;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\DeduplicationStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * In-memory Deduplication Store for testing.
 *
 * Stores processed message IDs in memory instead of database.
 */
final class DeduplicationInMemoryStore implements DeduplicationStore
{
    /** @var array<string, true> */
    private array $processedMessageIds = [];

    private int $duplicateCount = 0;
    private int $processedCount = 0;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function isDuplicate(Id $messageId, string $messageName): bool
    {
        $key = (string) $messageId;

        // Check if already processed
        if (isset($this->processedMessageIds[$key])) {
            ++$this->duplicateCount;
            $this->logger->info('Duplicate message detected by deduplication store', [
                'message_id' => $key,
                'message_name' => $messageName,
            ]);

            return true;
        }

        // Mark as processed
        $this->processedMessageIds[$key] = true;
        ++$this->processedCount;

        return false;
    }

    /**
     * Get count of duplicate messages that were detected.
     */
    public function getDuplicateCount(): int
    {
        return $this->duplicateCount;
    }

    /**
     * Get count of unique messages that were processed.
     */
    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    /**
     * Check if a message ID has been processed.
     */
    public function hasProcessed(string $messageId): bool
    {
        return isset($this->processedMessageIds[$messageId]);
    }

    /**
     * Clear all stored message IDs.
     */
    public function clear(): void
    {
        $this->processedMessageIds = [];
        $this->duplicateCount = 0;
        $this->processedCount = 0;
    }
}
