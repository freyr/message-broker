<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;

/**
 * Insert-or-ignore deduplication keyed by (message_id, consumer).
 * acquire() runs INSIDE the handler's PDO transaction so the dedup entry
 * commits/rolls back atomically with the handler's changes.
 */
final readonly class PdoDeduplicationStore
{
    public function __construct(
        private PDO $pdo,
        private Platform $platform,
    ) {}

    /** @return bool false = already processed (duplicate), skip the handler */
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

    public function cleanup(int $olderThanMs): int
    {
        // TODO slice 1: DELETE ... WHERE created_at < :threshold, return count.
        return 0;
    }
}
