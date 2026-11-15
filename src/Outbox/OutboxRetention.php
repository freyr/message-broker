<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

/**
 * Capacity and retention of the outbox table. The relay never deletes rows;
 * they leave the table here, one storage unit at a time (PostgreSQL: one
 * daily partition). {@see PostgreSqlOutboxRetention} is the PostgreSQL
 * implementation.
 */
interface OutboxRetention
{
    /**
     * Make sure the table can take rows from today to today + $daysAhead days.
     * Safe to repeat: a unit that already exists is skipped.
     *
     * @return list<string> names of the storage units that now exist for that range
     */
    public function ensureCapacity(int $daysAhead): array;

    /**
     * Drop every storage unit that lies wholly before the cutoff (today
     * 00:00 UTC minus $retentionDays). A unit that holds unpublished rows is
     * kept and reported in RetentionReport::$kept, unless $dropUnpublished is
     * true; then it is dropped with them and reported in
     * RetentionReport::$unpublished. With $dryRun nothing changes and the
     * report shows what would change.
     *
     * Must run outside a transaction.
     */
    public function purge(int $retentionDays, bool $dryRun = false, bool $dropUnpublished = false): RetentionReport;
}
