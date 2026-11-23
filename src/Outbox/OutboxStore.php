<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

/**
 * Outbox table access — the storage seam behind the producer (write side) and
 * the relay (drain side). The PostgreSQL adapter speaks plain PDO on
 * PostgreSQL; an adapter for another database implements this same
 * contract, so the producer, relay, and replay service stay storage-agnostic.
 *
 * Contract for implementations:
 *  - insert() must run on the caller's connection/transaction so the outbox row
 *    commits atomically with the application's own state change.
 *  - a published row is never claimed again. By default the drain keeps it
 *    with its published_at, and it leaves the table only through retention
 *    ({@see OutboxRetention}); an implementation can offer, as an option, to
 *    delete it in the claim transaction instead. An unpublished row that is not abandoned is
 *    never dropped by retention unless the operator opts in
 *    (drop-unpublished). An abandoned row does not keep its storage unit.
 *  - an abandoned row (see abandon()) is open but out of the drain: the
 *    claim, failing() and the outcome writes ignore it.
 *  - there is no ordering guarantee: rows of one lane publish in any order.
 */
interface OutboxStore
{
    public function insert(OutboxRecord $record): void;

    /**
     * Claim up to $limit unpublished, due rows of one lane that are not
     * abandoned (published_at IS NULL, abandoned_at IS NULL,
     * available_at <= now) inside a transaction owned
     * by this store, hand them to $publish, apply its outcome (set
     * published_at on published rows, or delete them when the store is
     * configured so; bump attempts and available_at on
     * retried rows; a row that is abandoned meanwhile is not changed), and
     * commit. Concurrent claimers skip each other's locked
     * rows — they never block. If $publish throws, the transaction rolls back
     * and the rows are instantly reclaimable; a dead connection has the same
     * effect. An empty claim returns 0 without invoking $publish. The
     * outcome's ids MUST be drawn from the claimed batch, and no id may
     * appear in both publishedIds and retryAtMs.
     *
     * @param callable(non-empty-list<OutboxRecord>): ClaimOutcome $publish
     *
     * @return int rows published (marked or deleted)
     */
    public function drainClaimed(string $lane, int $limit, callable $publish): int;

    /**
     * Mark each row with this message id that is not published and not
     * abandoned (published_at IS NULL, abandoned_at IS NULL) as abandoned:
     * set abandoned_at to now. The relay never claims an abandoned row again.
     * A replay makes a second row with the same id, so the count can be above
     * 1. An id that no row has (also a value that is not a valid id) gives 0.
     * One statement on the connection of the store: it opens no transaction,
     * and it joins a transaction that the caller opened.
     *
     * @return int rows marked abandoned
     */
    public function abandon(string $id): int;

    /**
     * The open rows of one lane (published_at IS NULL, abandoned_at IS NULL)
     * with attempts >= $minAttempts, also the rows that are not due yet: at
     * most $limit, by attempts (highest first), then by created_at (oldest
     * first). Never the body, only its size.
     *
     * @return list<FailingRow>
     *
     * @throws \InvalidArgumentException when $minAttempts or $limit is below 1
     */
    public function failing(string $lane, int $minAttempts, int $limit): array;
}
