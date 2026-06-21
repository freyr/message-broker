<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage;

use Freyr\MessageBroker\Serializer\Format;
use PDOStatement;

/**
 * SQL dialect seam. MySQL ships first (slice 1), PostgreSQL second (slice 2).
 * Only the statements that differ between platforms live here.
 */
interface Platform
{
    public function insertOutboxSql(): string;

    /**
     * Try to acquire exclusive, session-scoped ownership of one lane
     * (MySQL: GET_LOCK, PostgreSQL: pg_try_advisory_lock). Self-releases if
     * the relay's connection dies — crash recovery without lease bookkeeping.
     * One relay per lane is what guarantees total in-order publishing.
     */
    public function tryAcquireLaneSql(): string;

    /**
     * Contiguous prefix of one owned lane, ordered by id (UUIDv7 = time).
     * No SKIP LOCKED — skipping a locked head row would violate ordering;
     * exclusivity is lane-level, via the advisory lock.
     */
    public function selectLanePrefixSql(): string;

    /** Insert-or-ignore for the deduplication table. */
    public function insertDeduplicationSql(): string;

    /**
     * DDL for outbox_messages (format-specific body column), message_deduplication,
     * dead_letters. JSON setup → body JSON; Avro setup → body LONGBLOB (E1).
     *
     * @return list<string>
     */
    public function schemaSql(Format $format): array;

    /**
     * Bind the body parameter on a prepared statement. The body is opaque wire
     * bytes; PostgreSQL stores it as BYTEA and needs a binary (PARAM_LOB) bind,
     * MySQL accepts a plain string into JSON/BLOB columns. The dialect owns this.
     */
    public function bindBody(PDOStatement $statement, string $name, string $body): void;

    /**
     * Normalize a body column read back from the database into a string.
     * PostgreSQL returns BYTEA as a stream resource; MySQL returns a string.
     */
    public function readBody(mixed $value): string;
}
