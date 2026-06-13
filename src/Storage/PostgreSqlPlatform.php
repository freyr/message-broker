<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage;

use Freyr\MessageBroker\Serializer\Format;

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

    public function schemaSql(Format $format): array
    {
        // TODO slice 4: JSONB metadata; body JSONB (json) / BYTEA (avro);
        // TIMESTAMP(3); index (lane, id). $format selects the body type.
        return [];
    }
}
