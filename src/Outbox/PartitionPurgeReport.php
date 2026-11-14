<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use DateTimeImmutable;

/** Outcome of one OutboxPartitionManager::purge() run. */
final readonly class PartitionPurgeReport
{
    /**
     * @param list<string> $dropped daily partitions dropped (or, on a dry run, that would be)
     * @param array<string, int> $kept expired daily partitions kept: name => unpublished row count
     */
    public function __construct(
        public DateTimeImmutable $cutoff,
        public array $dropped,
        public array $kept,
        public int $defaultRowsDeleted,
        public int $defaultRowsRemaining,
    ) {}

    /** True when an expired partition still holds unpublished rows — a stuck relay. */
    public function hasStuckRows(): bool
    {
        return $this->kept !== [];
    }
}
