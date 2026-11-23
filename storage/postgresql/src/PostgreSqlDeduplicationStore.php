<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;
use PDOStatement;

/**
 * PostgreSQL insert-or-ignore deduplication keyed by (message_id, consumer) — the default
 * DeduplicationStore implementation. Constructed with the consumer's own PDO
 * connection so acquire() joins the consumer's transaction. The insert is
 * prepared once per store (one parse per connection, not per message).
 */
final class PostgreSqlDeduplicationStore implements DeduplicationStore
{
    private ?PDOStatement $insert = null;

    /** @param positive-int $deleteChunkSize rows removed per cleanup statement */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $deleteChunkSize = 10_000,
    ) {}

    /** @return bool false = already processed (duplicate), skip dispatch */
    public function acquire(IncomingMessage $message, string $consumer): bool
    {
        $this->insert ??= $this->pdo->prepare(<<<'SQL'
            INSERT INTO message_deduplication (message_id, consumer, message_name, created_at)
            VALUES (:message_id, :consumer, :message_name, :created_at)
            ON CONFLICT (message_id, consumer) DO NOTHING
            SQL);
        // rowCount() = 0 on conflict: the duplicate signal.
        $this->insert->execute([
            'message_id' => $message->messageId,
            'consumer' => $consumer,
            'message_name' => $message->messageName,
            'created_at' => EpochMillis::toDateTime(EpochMillis::now())->format('Y-m-d H:i:s.v'),
        ]);

        return $this->insert->rowCount() === 1;
    }

    /**
     * Prune entries created before the given instant, in chunks: each chunk
     * is its own short statement (idx_dedup_created_at finds the rows), so the
     * cleanup holds no long transaction and no lock on many rows.
     *
     * @return int rows removed
     */
    public function cleanup(int $beforeEpochMs): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM message_deduplication WHERE ctid IN'
            .' (SELECT ctid FROM message_deduplication WHERE created_at < :threshold LIMIT :chunk)',
        );
        $statement->bindValue('threshold', EpochMillis::toDateTime($beforeEpochMs)->format('Y-m-d H:i:s.v'));
        $statement->bindValue('chunk', $this->deleteChunkSize, PDO::PARAM_INT);

        $removed = 0;
        do {
            $statement->execute();
            $chunk = $statement->rowCount();
            $removed += $chunk;
        } while ($chunk === $this->deleteChunkSize);

        return $removed;
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
