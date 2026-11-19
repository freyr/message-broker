<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * Access to the webhook_deliveries table: the storage seam behind
 * {@see WebhookProducer} (write side) and {@see DeliveryLoop} (claim and
 * result side). The PostgreSQL adapter implements it; the test kit has an
 * in-memory fake. Both pass the contract test case of the test kit.
 *
 * Rules for implementations:
 *  - "Unfinished" means completed_at IS NULL. "Due" means
 *    available_at <= now. A finished row is never claimed again.
 *  - A claim is a lease, not a lock that a transaction holds: it moves
 *    available_at to now + $leaseMs and commits before it returns. The
 *    returned lease value is that new available_at. A worker that stops
 *    after the claim leaves the row due again when the lease ends.
 *  - The lease check: complete() changes a row, and writes its dead letter,
 *    only when the row is unfinished and its available_at is equal to the
 *    lease value of the claimed delivery. It skips each other row. Thus a
 *    worker that writes after its lease ended changes nothing.
 *  - Times are epoch milliseconds.
 *  - There is no order guarantee inside a lane.
 */
interface DeliveryStore
{
    /**
     * Insert the rows on the connection of the caller, so they commit or roll
     * back with the transaction of the application. It opens no transaction.
     * An empty list writes nothing.
     *
     * @param list<DeliveryRecord> $records
     */
    public function insert(array $records): void;

    /**
     * Claim due, unfinished rows of the given lanes: at most
     * $limitForEachLane rows of each lane and at most $total rows in all.
     * Set available_at of each claimed row to now + $leaseMs and commit in a
     * transaction of the store before it returns. Concurrent claimers skip
     * the rows of each other (for a database: FOR UPDATE SKIP LOCKED); they
     * never wait. An empty lane list returns an empty list.
     *
     * @param list<string> $lanes
     *
     * @return list<ClaimedDelivery> each with the record (attempts as stored) and its lease value
     */
    public function claim(array $lanes, int $limitForEachLane, int $total, int $leaseMs): array;

    /**
     * Write the outcomes in one transaction of the store: for a delivered
     * outcome the final state delivered and completed_at; for a retry the
     * attempts and the new available_at; for a dead outcome the dead letter
     * and the final state dead with completed_at. Each outcome also writes
     * its attempts value. The lease check applies to each outcome. The dead
     * letter follows the open row rule of the dead letter store: one open
     * row for each pair of message ID and source. When the transaction
     * fails, nothing is written and the error propagates.
     *
     * @param list<DeliveryCompletion> $outcomes
     *
     * @return list<string> the ids of the rows that this call changed (the rows that passed the lease check)
     */
    public function complete(array $outcomes): array;

    /**
     * Claim due, unfinished rows of any lane with created_at earlier than
     * $before: at most $limit rows, the oldest first. The lease is the same
     * as in claim(). The delivery loop gives these rows a dead letter with
     * the reason "expired".
     *
     * @param int $before epoch milliseconds: now minus the maximum age
     *
     * @return list<ClaimedDelivery>
     */
    public function claimExpired(int $before, int $limit, int $leaseMs): array;
}
