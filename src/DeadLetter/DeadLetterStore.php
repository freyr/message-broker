<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

/**
 * Dead-letter table access — the storage seam behind the consumers (write
 * side) and the replay service plus DLQ console tooling (read/replay side).
 * The default {@see PdoDeadLetterStore} speaks plain PDO; an alternative
 * backing (e.g. a Doctrine DBAL store) implements this same contract, so the
 * consumers, replay service, and commands stay storage-agnostic.
 */
interface DeadLetterStore
{
    public function store(DeadLetter $deadLetter): void;

    public function find(string $id): ?DeadLetter;

    /**
     * Newest first (failed_at DESC), filtered by any combination of message
     * name, source queue/topic, a lower failed_at bound, and replay state
     * (replayed: false = not yet replayed, true = replayed, null = both).
     *
     * @return list<DeadLetter>
     */
    public function list(
        ?string $messageName = null,
        ?string $source = null,
        ?int $sinceMs = null,
        int $limit = 100,
        int $offset = 0,
        ?bool $replayed = null,
    ): array;

    /** Same filters as list() — the paging/dry-run companion. */
    public function count(
        ?string $messageName = null,
        ?string $source = null,
        ?int $sinceMs = null,
        ?bool $replayed = null,
    ): int;

    /** Replay keeps the row for audit — marks replayed_at instead of deleting. */
    public function markReplayed(string $id): void;

    /** @return int rows removed */
    public function purge(?string $messageName = null, ?string $source = null, ?int $olderThanMs = null): int;
}
