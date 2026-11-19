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
     * The order of $lanes is the priority when $total cuts the claim: a lane
     * that is earlier in the list gets its rows (up to $limitForEachLane)
     * before a later lane gets one. The delivery loop gives the lanes in a
     * new random order for each claim, so no lane is always last.
     *
     * A row is due when its available_at is at or before the present time of
     * the store, and the comparison has millisecond precision: a row whose
     * lease is 0 ms is due again for the next claim.
     *
     * @param list<string> $lanes the lanes to claim from, in priority order
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
     * Abandon old rows, for an operator: at most $limit unfinished rows with
     * created_at earlier than $before, of the given lanes (null: of all
     * lanes; an empty list: of no lane), in no defined order. Each row gets
     * its dead letter ({@see DeliveryDeadLetter::abandoned()}: error class
     * `abandoned`, source `webhook:<lane>`) and the final state dead with
     * completed_at, in one transaction of the store. The dead letter follows
     * the open row rule, as in complete(). When the transaction fails,
     * nothing is written and the error propagates.
     *
     * A lease does not protect a row: a leased row is abandoned too, and the
     * later complete() of its worker changes nothing (the row is finished).
     * Concurrent writers skip the rows of each other: a row that a different
     * session locks at this time is skipped (for a database: FOR UPDATE SKIP
     * LOCKED), so a call can return fewer than $limit rows while more are
     * left. Call it again until it returns 0.
     *
     * @param int $before epoch milliseconds: a row whose created_at is earlier is abandoned
     * @param list<string>|null $lanes the lanes to abandon rows of; null = all lanes
     * @param int $limit the most rows of this call
     *
     * @return int the number of rows that this call abandoned
     */
    public function abandon(int $before, ?array $lanes, int $limit): int;

    /**
     * Count, with no change, the unfinished rows with created_at earlier
     * than $before, for each lane of the given lanes (null: of all lanes; an
     * empty list: of no lane): the rows that abandon() with the same
     * arguments would take. A lane with no such row is not in the result.
     *
     * @param int $before epoch milliseconds
     * @param list<string>|null $lanes null = all lanes
     *
     * @return array<string, int> lane => row count, in the byte order of the lane names
     *                            (PHP turns a numeric lane name into an int key; cast it back to string)
     */
    public function countUnfinished(int $before, ?array $lanes): array;
}
