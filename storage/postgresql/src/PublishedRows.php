<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

/**
 * What PostgreSqlOutboxStore does with a row after a confirmed publish.
 * Only the store of the relay uses it; the store of the producer can keep
 * the default. The values are strings, so a deployment can read them from
 * its configuration: PublishedRows::from('delete').
 *
 * Measured with `make bench-outbox-cleanup` (docs/STORAGE.md of this package):
 * Delete uses less PostgreSQL CPU and half the WAL while the relay drains a
 * backlog; under a steady load with autovacuum, Mark used less CPU (median of
 * 4 runs) and kept a stable claim plan.
 */
enum PublishedRows: string
{
    /**
     * Set published_at in the claim transaction. The row stays until
     * retention drops its daily partition: an audit trail of each published
     * message for the retention period. The default.
     */
    case Mark = 'mark';

    /**
     * Delete the row in the claim transaction, after the confirmed publish.
     * The table holds only open rows; VACUUM removes the dead rows. Retention
     * still creates the partitions ahead and drops the expired ones.
     */
    case Delete = 'delete';
}
