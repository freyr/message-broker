<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;

/**
 * Insert-or-ignore deduplication keyed by (message_id, consumer).
 * acquire() runs INSIDE the consumer's PDO transaction so the dedup entry
 * commits/rolls back atomically with the dispatched work.
 *
 * TODO: extract a DeduplicationStore interface (mirroring Outbox\OutboxStore) so an
 * alternative backing — e.g. a Doctrine DBAL store — can be plugged into the consumer;
 * this class then becomes the PDO implementation behind it.
 */
final readonly class PdoDeduplicationStore
{
    public function __construct(
        private PDO $pdo,
        private Platform $platform,
    ) {}

    /** @return bool false = already processed (duplicate), skip dispatch */
    public function acquire(IncomingMessage $message, string $consumer): bool
    {
        $statement = $this->pdo->prepare($this->platform->insertDeduplicationSql());
        $statement->execute([
            'message_id' => $message->messageId,
            'consumer' => $consumer,
            'message_name' => $message->messageName,
            'created_at' => EpochMillis::toDateTime(EpochMillis::now())->format('Y-m-d H:i:s.v'),
        ]);

        return $statement->rowCount() === 1;
    }

    /** Prune entries created before the given instant. @return int rows removed */
    public function cleanup(int $beforeEpochMs): int
    {
        $statement = $this->pdo->prepare('DELETE FROM message_deduplication WHERE created_at < :threshold');
        $statement->execute([
            'threshold' => EpochMillis::toDateTime($beforeEpochMs)->format('Y-m-d H:i:s.v'),
        ]);

        return $statement->rowCount();
    }

    /** Count entries created before the given instant (dry-run for cleanup). */
    public function countOlderThan(int $beforeEpochMs): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM message_deduplication WHERE created_at < :threshold');
        $statement->execute([
            'threshold' => EpochMillis::toDateTime($beforeEpochMs)->format('Y-m-d H:i:s.v'),
        ]);

        return (int) $statement->fetchColumn();
    }
}
