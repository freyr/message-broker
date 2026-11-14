<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport;

use Freyr\MessageBroker\Consumer\IncomingMessage;

/**
 * Consumer-side deduplication — the storage seam that turns at-least-once
 * delivery into exactly-once processing. The default
 * {@see PdoDeduplicationStore} speaks plain PDO; an alternative backing
 * (e.g. a Doctrine DBAL store) implements this same contract, so the
 * consumers stay storage-agnostic.
 *
 * Contract for implementations:
 *  - acquire() must run INSIDE the consumer's transaction so the dedup entry
 *    commits/rolls back atomically with the dispatched work — that atomicity
 *    is the exactly-once guarantee.
 */
interface DeduplicationStore
{
    /** @return bool false = already processed (duplicate), skip dispatch */
    public function acquire(IncomingMessage $message, string $consumer): bool;

    /** Prune entries created before the given instant. @return int rows removed */
    public function cleanup(int $beforeEpochMs): int;

    /** Count entries created before the given instant (dry-run for cleanup). */
    public function countOlderThan(int $beforeEpochMs): int;
}
