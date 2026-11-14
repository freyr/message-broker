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
     * Try to acquire exclusive, session-scoped ownership of one lane
     * (pg_try_advisory_lock). Self-releases if
     * the relay's connection dies — crash recovery without lease bookkeeping.
     * One relay per lane is what guarantees total in-order publishing.
     */
    public function tryAcquireLaneSql(): string;

    /**
     * Release the lane lock acquired via tryAcquireLaneSql so a restarting or
     * standby relay can take the lane over immediately. Bound with :lane.
     * Implementations MUST derive the lock key identically to tryAcquireLaneSql()
     * (same hash, same casts) — a mismatched key makes the release a silent no-op.
     */
    public function releaseLaneSql(): string;

    /**
     * Contiguous prefix of one owned lane, ordered by id (UUIDv7 = time).
     * No SKIP LOCKED — skipping a locked head row would violate ordering;
     * exclusivity is lane-level, via the advisory lock.
     */
    public function selectLanePrefixSql(): string;

    /**
     * Competing drain (opt-in): up to :limit eligible rows of one lane
     * (available_at <= :now), ordered by id — a FIFO bias, not a contract —
     * with FOR UPDATE SKIP LOCKED so concurrent claimers never block each
     * other. Bound with :lane, :now, :limit. Contrast selectLanePrefixSql():
     * eligibility moves into SQL here because rows overtake freely; the
     * ordered drain keeps its head-check-in-code semantics (D17).
     */
    public function selectClaimBatchSql(): string;

    /** Insert-or-ignore for the deduplication table. */
    public function insertDeduplicationSql(): string;

    /**
     * DDL for outbox_messages, message_deduplication,
     * dead_letters. The body column is BYTEA for both formats.
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
