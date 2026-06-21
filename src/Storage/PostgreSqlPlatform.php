<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage;

use Freyr\MessageBroker\Serializer\Format;
use PDO;
use PDOStatement;

/**
 * PostgreSQL dialect (DDL deferred to slice 4). Differences from MySQL:
 *   - INSERT IGNORE            → INSERT ... ON CONFLICT DO NOTHING
 *   - GET_LOCK(name)           → pg_try_advisory_lock(int) — PG advisory locks
 *                                take integer keys, not strings, so the lane is
 *                                hashed server-side via hashtext()
 *   - JSON columns             → JSONB
 *   - DATETIME(3)              → TIMESTAMP(3) (UTC by convention, like MySQL)
 */
final readonly class PostgreSqlPlatform implements Platform
{
    public function insertOutboxSql(): string
    {
        return <<<'SQL'
            INSERT INTO outbox_messages
                (id, lane, message_key, message_name, metadata, body, headers, created_at, available_at, attempts)
            VALUES
                (:id, :lane, :message_key, :message_name, :metadata, :body, :headers, :created_at, :available_at, 0)
            SQL;
    }

    public function tryAcquireLaneSql(): string
    {
        // Session-scoped, self-releasing on disconnect — same semantics as
        // MySQL GET_LOCK. Returns true on success, false if owned elsewhere.
        // NOTE: hashtext() can collide across lane names (int4 keyspace) —
        // false contention is safe but stalls; assess in slice 4.
        return 'SELECT pg_try_advisory_lock(hashtext(:lane))';
    }

    public function selectLanePrefixSql(): string
    {
        return <<<'SQL'
            SELECT * FROM outbox_messages
            WHERE lane = :lane
            ORDER BY id
            LIMIT :limit
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
        // The body is opaque wire bytes (the relay byte-pumps it; Debezium reads
        // it with a binary converter). On PG it is BYTEA for BOTH formats — JSON
        // text round-trips through BYTEA unchanged, and we avoid per-format binding
        // rules. metadata/headers stay JSONB (they ARE decoded/queried). $format
        // therefore does not change PG column types; it does on MySQL (JSON vs BLOB).
        return [
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS outbox_messages (
                    id CHAR(36) NOT NULL PRIMARY KEY,
                    lane VARCHAR(64) NOT NULL,
                    message_key VARCHAR(255) NOT NULL,
                    message_name VARCHAR(255) NOT NULL,
                    metadata JSONB NOT NULL,
                    body BYTEA NOT NULL,
                    headers JSONB NOT NULL,
                    created_at TIMESTAMP(3) NOT NULL,
                    available_at TIMESTAMP(3) NOT NULL,
                    attempts SMALLINT NOT NULL DEFAULT 0
                )
                SQL,
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_outbox_drain ON outbox_messages (lane, id)
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
                CREATE TABLE IF NOT EXISTS dead_letters (
                    id CHAR(36) NOT NULL PRIMARY KEY,
                    source VARCHAR(255) NOT NULL,
                    message_id VARCHAR(255) NOT NULL,
                    message_name VARCHAR(255) NOT NULL,
                    body BYTEA NOT NULL,
                    headers JSONB NOT NULL,
                    error_class VARCHAR(255) NOT NULL,
                    error_message TEXT NOT NULL,
                    error_trace TEXT NOT NULL,
                    attempts SMALLINT NOT NULL DEFAULT 0,
                    failed_at TIMESTAMP(3) NOT NULL,
                    replayed_at TIMESTAMP(3) NULL
                )
                SQL,
        ];
    }
}
