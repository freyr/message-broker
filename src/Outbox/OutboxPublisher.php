<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Throwable;

/**
 * Transport port of the {@see Relay}: sends claimed outbox rows to a broker.
 * A transport adapter (AMQP, SQS, ...) implements it.
 *
 * Contract for implementations:
 *  - The relay never passes more than {@see maxBatchSize()} rows to one
 *    publishBatch() call. A retried row (attempts > 0) goes alone, in a call
 *    of one row.
 *  - publishBatch() returns the IDs of the rows that the transport REFUSED
 *    (for example a broker nack, or a failed entry of an SQS batch request).
 *    The relay backs these rows off. Every other row of the call counts as
 *    published, so a publisher must return an ID only for a row that the
 *    transport did not accept. A returned ID must come from the batch.
 *  - publishBatch() throws when the connection or the channel fails, or when a
 *    confirmation times out. Then NO row of that call counts as published: the
 *    relay backs off all rows of the call, commits, and stops the worker
 *    (crash-only). A publisher that sends in several requests inside one call
 *    and fails in the middle must throw, not return the IDs of the failed
 *    part only, unless it can say for each row whether the transport accepted
 *    it. The relay does not roll back rows that the transport already
 *    accepted; the consumer-side deduplication absorbs the repeat.
 *  - The publisher owns its transport resources (connection, channel)
 *    exclusively: one publisher serves one relay.
 */
interface OutboxPublisher
{
    /**
     * Publish the rows and wait until the transport confirms them.
     *
     * @param non-empty-list<OutboxRecord> $batch at most maxBatchSize() rows
     *
     * @return list<string> IDs of the rows that the transport refused; they back off, the other rows count as published
     *
     * @throws Throwable when the connection or the channel fails or a confirmation times out
     */
    public function publishBatch(array $batch): array;

    /**
     * The largest batch that publishBatch() accepts in one call. The relay
     * cuts a claim into chunks of this size, so a failure backs off one chunk
     * only. A transport with no limit returns PHP_INT_MAX; SQS returns 10.
     *
     * @return positive-int
     */
    public function maxBatchSize(): int;
}
