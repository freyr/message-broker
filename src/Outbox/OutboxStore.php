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
