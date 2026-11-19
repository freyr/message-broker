<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use Freyr\MessageBroker\Outbox\RetentionReport;

/**
 * Capacity and retention of the webhook_deliveries table, with the rules of
 * the outbox retention ({@see \Freyr\MessageBroker\Outbox\OutboxRetention}):
 * finished rows leave the table one storage unit at a time (PostgreSQL: one
 * daily partition). The PostgreSQL adapter provides the implementation.
 *
 * The retention period must be the retention period of the delivery loop:
 * the loop checks at its start that each row reaches a final state before
 * its unit expires.
 */
interface DeliveryRetention
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
     * 00:00 UTC minus $retentionDays) and holds no unfinished row
     * (completed_at IS NULL). A unit with unfinished rows is kept and
     * reported in RetentionReport::$kept (name => count of the unfinished
     * rows); there is no option to drop it. RetentionReport::$unpublished and
     * RetentionReport::$abandoned stay empty. With $dryRun nothing changes
     * and the report shows what would change.
     *
     * Must run outside a transaction.
     */
    public function purge(int $retentionDays, bool $dryRun = false): RetentionReport;
}
