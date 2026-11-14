<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage;

use Freyr\MessageBroker\Serializer\Format;
use PDO;
use PDOStatement;

/**
 * PostgreSQL dialect (the only supported database, 14+):
 *   - outbox_messages          → range-partitioned by created_at (one partition
 *                                per UTC day, no DEFAULT partition); managed
 *                                by OutboxPartitionManager or pg_partman
 *   - ids                      → native UUID
 *   - deduplication insert     → INSERT ... ON CONFLICT DO NOTHING
 *   - headers                  → JSONB
 *   - timestamps               → TIMESTAMP(3), UTC by convention
 *   - body column              → BYTEA (opaque wire bytes, both JSON and Avro)
 */
final readonly class PostgreSqlPlatform implements Platform
{
    public function insertOutboxSql(): string
    {
        return <<<'SQL'
            INSERT INTO outbox_messages
                (id, lane, message_name, body, headers, created_at, available_at, attempts)
            VALUES
                (:id, :lane, :message_name, :body, :headers, :created_at, :available_at, 0)
            SQL;
    }

    public function selectClaimBatchSql(): string
    {
        // Oldest-due first is a fairness bias, not an ordering contract. The
        // partial index idx_outbox_pending serves this query; published rows
        // are outside it.
        return <<<'SQL'
            SELECT id, lane, message_name, body, headers, created_at, available_at, attempts
            FROM outbox_messages
            WHERE lane = :lane AND published_at IS NULL AND available_at <= :now
            ORDER BY available_at
            LIMIT :limit
            FOR UPDATE SKIP LOCKED
            SQL;
    }

    public function insertDeduplicationSql(): string
    {
        // rowCount() = 0 on conflict — same duplicate signal as INSERT IGNORE.
        return <<<'SQL'
            INSERT INTO message_deduplication (message_id, consumer, message_name, created_at)
            VALUES (:message_id, :consumer, :message_name, :created_at)
            ON CONFLICT (message_id, consumer) DO NOTHING
            SQL;
    }

    public function bindBody(PDOStatement $statement, string $name, string $body): void
    {
        // BYTEA requires a binary bind; PARAM_LOB makes pdo_pgsql send binary
        // format. JSON text bound this way round-trips through BYTEA unchanged.
        $statement->bindValue($name, $body, PDO::PARAM_LOB);
    }

    public function readBody(mixed $value): string
    {
        // pdo_pgsql returns BYTEA as a stream resource.
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        if (!is_string($value)) {
            throw new \RuntimeException('Body column is not a string or stream');
        }

        return $value;
    }

    public function schemaSql(Format $format): array
    {
        // The body is opaque wire bytes (the relay byte-pumps it). It is BYTEA
        // for BOTH formats — JSON text round-trips through BYTEA unchanged, and
        // we avoid per-format binding rules. $format therefore does not change
        // column types.
        //
        // outbox_messages is partitioned by created_at: the partition key is
        // known at insert (published_at is not), and the primary key of a
        // partitioned table must include it. The daily partitions come from
        // OutboxPartitionManager (or pg_partman). There is no DEFAULT
        // partition, so retention can DETACH ... CONCURRENTLY; an insert for
        // a day with no partition fails, so partitions are created ahead.
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
                    replayed_at TIMESTAMP(3) NULL
                )
                SQL,
        ];
    }
}
