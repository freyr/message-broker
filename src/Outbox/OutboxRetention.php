<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

/**
 * Capacity and retention of the outbox table. By default the relay marks
 * published rows and never deletes them; they leave the table here, one
 * storage unit at a time (PostgreSQL: one daily partition). A store that
 * deletes published rows still needs the capacity and the drop of the
 * expired units. The PostgreSQL adapter provides the implementation.
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
     * 00:00 UTC minus $retentionDays). "Unpublished" means not published and
     * not abandoned (published_at IS NULL AND abandoned_at IS NULL). A unit
     * that holds unpublished rows is kept and reported in
     * RetentionReport::$kept, unless $dropUnpublished is true; then it is
     * dropped with them and reported in RetentionReport::$unpublished. An
     * abandoned row does not keep its unit: a dropped unit (or, on a dry run,
     * one that would be dropped) with abandoned rows is reported in
     * RetentionReport::$abandoned. With $dryRun nothing changes and the
     * report shows what would change.
     *
     * Must run outside a transaction.
     */
    public function purge(int $retentionDays, bool $dryRun = false, bool $dropUnpublished = false): RetentionReport;
}
