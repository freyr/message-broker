<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use Freyr\MessageBroker\Storage\SchemaInstaller;
use PDO;

/**
 * PostgreSQL schema (14+):
 *   - outbox_messages          → range-partitioned by created_at (one partition
 *                                per UTC day, no DEFAULT partition); managed
 *                                by PostgreSqlOutboxRetention or pg_partman
 *   - ids                      → native UUID
 *   - deduplication insert     → INSERT ... ON CONFLICT DO NOTHING
 *   - headers                  → JSONB
 *   - timestamps               → TIMESTAMP(3), UTC by convention
 *   - body column              → BYTEA (opaque wire bytes)
 */
final readonly class PostgreSqlSchemaInstaller implements SchemaInstaller
{
    private PostgreSqlOutboxRetention $retention;

    public function __construct(
        private PDO $pdo,
    ) {
        $this->retention = new PostgreSqlOutboxRetention($pdo);
    }

    public function sql(int $daysAhead): array
    {
        // For a new database: the partition DDL is not idempotent (ATTACH).
        return [...self::tableSql(), ...$this->retention->partitionDdl($daysAhead)];
    }

    public function install(int $daysAhead): void
    {
        foreach (self::tableSql() as $statement) {
            $this->pdo->exec($statement);
        }
        $this->retention->ensureCapacity($daysAhead); // skips partitions that are already attached
    }

    /** @return list<string> */
    private static function tableSql(): array
    {
        // The body is opaque wire bytes (the relay byte-pumps it). It is
        // BYTEA: JSON text round-trips through BYTEA unchanged.
        //
        // outbox_messages is partitioned by created_at: the partition key is
        // known at insert (published_at is not), and the primary key of a
        // partitioned table must include it. The daily partitions come from
        // PostgreSqlOutboxRetention (or pg_partman). There is no DEFAULT
        // partition, so retention can DETACH ... CONCURRENTLY; an insert for
        // a day with no partition fails, so partitions are created ahead.
        //
        // dead_letters holds at most one open row (replayed_at IS NULL) for
        // one message_id and source: uq_dead_letters_open is the conflict
        // target of the upsert in PostgreSqlDeadLetterStore::store(). A
        // replayed row is outside the index, so a failure after a replay
        // gets a new row. replayable is false for a raw body.
        //
        // abandoned_at marks a row that an operator abandoned: the claim skips
        // it. idx_outbox_pending keeps its predicate: the claim still implies
        // it, and an abandoned row leaves the index with its partition.
        return [
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS outbox_messages (
                    id UUID NOT NULL,
                    lane VARCHAR(64) NOT NULL,
                    message_name VARCHAR(255) NOT NULL,
                    body BYTEA NOT NULL,
                    headers JSONB NOT NULL,
                    created_at TIMESTAMP(3) NOT NULL,
                    available_at TIMESTAMP(3) NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    published_at TIMESTAMP(3) NULL,
                    abandoned_at TIMESTAMP(3) NULL,
                    PRIMARY KEY (id, created_at)
                ) PARTITION BY RANGE (created_at)
                SQL,
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_outbox_pending ON outbox_messages (lane, available_at)
                    WHERE published_at IS NULL
                SQL,
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS message_deduplication (
                    message_id VARCHAR(255) NOT NULL,
                    consumer VARCHAR(128) NOT NULL,
                    message_name VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP(3) NOT NULL,
                    PRIMARY KEY (message_id, consumer)
                )
                SQL,
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_dedup_created_at ON message_deduplication (created_at)
                SQL,
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS dead_letters (
                    id UUID NOT NULL PRIMARY KEY,
                    source VARCHAR(255) NOT NULL,
                    message_id VARCHAR(255) NOT NULL,
                    message_name VARCHAR(255) NOT NULL,
                    body BYTEA NOT NULL,
                    headers JSONB NOT NULL,
                    error_class VARCHAR(255) NOT NULL,
                    error_message TEXT NOT NULL,
                    error_trace TEXT NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    failed_at TIMESTAMP(3) NOT NULL,
                    replayed_at TIMESTAMP(3) NULL,
                    replayable BOOLEAN NOT NULL DEFAULT TRUE
                )
                SQL,
            <<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uq_dead_letters_open ON dead_letters (message_id, source)
                    WHERE replayed_at IS NULL
                SQL,
        ];
    }
}
