<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

/**
 * Outbox table access — the storage seam behind the producer (write side) and
 * the relay (drain side). The default {@see PdoOutboxStore} speaks plain PDO;
 * an alternative backing (e.g. a Doctrine DBAL store) implements this same
 * contract, so the producer, relays, and replay service stay storage-agnostic.
 *
 * Contract for implementations:
 *  - insert() must run on the caller's connection/transaction so the outbox row
 *    commits atomically with the application's own state change.
 *  - the lane lock must be connection-scoped ownership that self-releases when
 *    the connection dies (crash recovery), so one relay serves one lane at a
 *    time — total in-order publishing per lane.
 */
interface OutboxStore
{
    public function insert(OutboxRecord $record): void;

    /**
     * Exclusive, session-scoped ownership of one lane via advisory lock.
     * Self-releases if this connection dies — crash recovery for free.
     * One relay per lane = total in-order publishing per lane.
     */
    public function tryAcquireLane(string $lane): bool;

    /** Release the lane lock so a restarting/standby relay can take over. */
    public function releaseLane(string $lane): void;

    /**
     * Contiguous prefix of one OWNED lane, ordered by id (UUIDv7 = time).
     * The caller checks head eligibility (available_at) on the first row only —
     * a backing-off head blocks the whole lane; nothing overtakes (D17).
     *
     * @return list<OutboxRecord>
     */
    public function lanePrefix(string $lane, int $limit): array;

    /**
     * Competing drain (opt-in; the AMQP parallel mode): claim up to $limit
     * eligible rows of one lane (available_at <= now, id order — a FIFO bias,
     * not a contract) inside a transaction owned by this store, hand them to
     * $publish, apply its outcome (delete published rows; bump attempts and
     * available_at on retried rows), and commit. Concurrent claimers skip
     * each other's locked rows — they never block. If $publish throws, the
     * transaction rolls back and the rows are instantly reclaimable; a dead
     * connection has the same effect (crash recovery for free). An empty
     * claim returns 0 without invoking $publish. The outcome's ids MUST be
     * drawn from the claimed batch — ids outside it would touch rows this
     * claim does not own — and no id may appear in both publishedIds and
     * retryAtMs.
     *
     * @param callable(non-empty-list<OutboxRecord>): ClaimOutcome $publish
     *
     * @return int rows published and deleted
     */
    public function drainClaimed(string $lane, int $limit, callable $publish): int;

    /** Successful publish — the row's job is done. Rows leave ONLY this way. */
    public function delete(string $id): void;

    /**
     * Batched variant for the relay's batched drain.
     *
     * @param list<string> $ids
     * @param positive-int $chunkSize
     */
    public function deleteBatch(array $ids, int $chunkSize = 500): void;

    /**
     * Transient publish failure: bump the head's available_at, increment
     * attempts. The lane waits out the backoff — there is no exhaustion,
     * no relay-side DLQ; a long-blocked lane is an operational alert.
     */
    public function scheduleRetry(string $id, int $availableAtMs): void;
}
