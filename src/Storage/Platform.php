<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage;

use Freyr\MessageBroker\Serializer\Format;
use PDOStatement;

/**
 * SQL dialect seam. PostgreSQL (PostgreSqlPlatform) is the only implementation;
 * the seam keeps the stores free of dialect-specific SQL.
 */
interface Platform
{
    public function insertOutboxSql(): string;

    /**
     * Claim query: up to :limit unpublished, due rows of one lane
     * (published_at IS NULL, available_at <= :now) with FOR UPDATE SKIP
     * LOCKED, so concurrent relay workers never block each other. Bound with
     * :lane, :now, :limit. No ordering contract.
     */
    public function selectClaimBatchSql(): string;

    /** Insert-or-ignore for the deduplication table. */
    public function insertDeduplicationSql(): string;

    /**
     * DDL for the partitioned outbox_messages table (with its DEFAULT
     * partition), message_deduplication and dead_letters. The body column is
     * BYTEA for both formats. Daily outbox partitions are not part of this
     * DDL; OutboxPartitionManager creates them.
     *
     * @return list<string>
     */
    public function schemaSql(Format $format): array;

    /**
     * Bind the body parameter on a prepared statement. The body is opaque wire
     * bytes; PostgreSQL stores it as BYTEA and needs a binary (PARAM_LOB) bind.
     */
    public function bindBody(PDOStatement $statement, string $name, string $body): void;

    /**
     * Normalize a body column read back from the database into a string.
     * PostgreSQL returns BYTEA as a stream resource.
     */
    public function readBody(mixed $value): string;
}
