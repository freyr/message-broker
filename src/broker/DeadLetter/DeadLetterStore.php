<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use InvalidArgumentException;

/**
 * Dead-letter table access — the storage seam behind the consumers (write
 * side) and the replay service plus DLQ console tooling (read/replay side).
 * The PostgreSQL adapter speaks plain PDO on
 * PostgreSQL; an adapter for another database implements this same contract,
 * so the consumers, replay service, and commands stay storage-agnostic.
 *
 * The store holds at most one open row (not replayed) for one message id and
 * source. Each read and the delete take a {@see DeadLetterFilter}; an empty
 * filter matches every row.
 *
 * A store is the table sink of the consumers: it extends {@see DeadLetterSink}
 * and keeps its store() method. store() is idempotent by id: when a row with
 * the same id exists (open or replayed), nothing changes. Otherwise, when an
 * open row of the same message id and source exists, that row keeps its id,
 * source and message id and gets the message name, error class, error
 * message, error trace, attempts, failed_at, body, headers and replayable mark
 * of the new dead letter. With no open row, a new row with the id of the dead
 * letter.
 */
interface DeadLetterStore extends DeadLetterSink
{
    public function find(string $id): ?DeadLetter;

    /**
     * The matching rows, newest first (failed_at descending, then id descending).
     *
     * @return list<DeadLetter>
     */
    public function list(DeadLetterFilter $filter, int $limit = 100, int $offset = 0): array;

    /** The count of the rows that list() would return with no limit. */
    public function count(DeadLetterFilter $filter): int;

    /**
     * The ids of the matching rows, oldest first (failed_at ascending, then id
     * ascending), at most $limit. There is no offset: a caller that changes
     * the rows calls it again for the next rows.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when $limit is below 1
     */
    public function ids(DeadLetterFilter $filter, int $limit): array;

    /**
     * One entry for each group (message name, source, error class) of the
     * matching rows: count descending, then message name, source and error
     * class ascending. No match gives an empty list.
     *
     * @return list<DeadLetterStat>
     */
    public function stats(DeadLetterFilter $filter): array;

    /**
     * Replay keeps the row for audit — marks replayed_at instead of deleting.
     * Conditional: sets the mark only when the row exists and replayed_at is
     * null, and returns true only when this call set it. An unknown id, a
     * value that is not a valid id, and a replayed row return false, with no
     * error and no change (the first replayed_at stays). The replay calls it
     * in its own transaction, so the mark rolls back with that transaction.
     */
    public function markReplayed(string $id): bool;

    /**
     * Deletes each matching row; an empty filter deletes every row.
     *
     * @return int rows removed
     */
    public function purge(DeadLetterFilter $filter): int;
}
