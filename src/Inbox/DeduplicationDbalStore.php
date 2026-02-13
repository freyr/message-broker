<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Freyr\Identity\Id;
use Psr\Log\LoggerInterface;

/**
 * DBAL Deduplication Store.
 *
 * Stores processed message IDs in database using Doctrine DBAL.
 * Uses database unique constraint for atomic duplicate detection.
 */
final readonly class DeduplicationDbalStore implements DeduplicationStore
{
    public function __construct(
        private Connection $connection,
        private string $tableName = 'message_broker_deduplication',
        private ?LoggerInterface $logger = null,
    ) {}

    public function isDuplicate(Id $messageId, string $messageName): bool
    {
        try {
            $this->connection->insert($this->tableName, [
                'message_id' => $messageId->toBinary(),
                'message_name' => $messageName,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            // Insert succeeded - message is new (not a duplicate)
            return false;
        } catch (UniqueConstraintViolationException $e) {
            // Insert failed due to unique constraint - message is a duplicate
            $this->logger?->info('Duplicate message detected by deduplication store', [
                'message_id' => (string) $messageId,
                'message_name' => $messageName,
            ]);

            return true;
        }
    }
}
