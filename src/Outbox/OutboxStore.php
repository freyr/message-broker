<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

/**
 * Outbox table access — the storage seam behind the producer (write side) and
 * the relay (drain side). The default {@see PostgreSqlOutboxStore} speaks plain
 * PDO on PostgreSQL; an adapter for another database implements this same
 * contract, so the producer, relay, and replay service stay storage-agnostic.
 *
 * Contract for implementations:
 *  - insert() must run on the caller's connection/transaction so the outbox row
 *    commits atomically with the application's own state change.
 *  - published rows are never deleted by the drain; they keep their
 *    published_at and leave the table only through retention
 *    ({@see OutboxRetention}). An unpublished row is never dropped by
 *    retention unless the operator opts in (drop-unpublished).
 *  - there is no ordering guarantee: rows of one lane publish in any order.
 */
interface OutboxStore
{
    public function insert(OutboxRecord $record): void;

    /**
     * Claim up to $limit unpublished, due rows of one lane
     * (published_at IS NULL, available_at <= now) inside a transaction owned
     * by this store, hand them to $publish, apply its outcome (set
     * published_at on published rows; bump attempts and available_at on
     * retried rows), and commit. Concurrent claimers skip each other's locked
     * rows — they never block. If $publish throws, the transaction rolls back
     * and the rows are instantly reclaimable; a dead connection has the same
     * effect. An empty claim returns 0 without invoking $publish. The
     * outcome's ids MUST be drawn from the claimed batch, and no id may
     * appear in both publishedIds and retryAtMs.
     *
     * @param callable(non-empty-list<OutboxRecord>): ClaimOutcome $publish
     *
     * @return int rows marked published
     */
    public function drainClaimed(string $lane, int $limit, callable $publish): int;
}
