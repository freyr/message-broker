<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage;

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
     * DDL for outbox_messages, message_deduplication, dead_letters.
     *
     * @return list<string>
     */
    public function schemaSql(): array;
}
