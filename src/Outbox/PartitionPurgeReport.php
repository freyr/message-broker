<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use DateTimeImmutable;

/** Outcome of one OutboxPartitionManager::purge() run. */
final readonly class PartitionPurgeReport
{
    /**
     * @param list<string> $dropped daily partitions dropped (or, on a dry run, that would be)
     * @param array<string, int> $unpublished dropped partitions that held unpublished rows: name => row count
     */
    public function __construct(
        public DateTimeImmutable $cutoff,
        public array $dropped,
        public array $unpublished,
    ) {}

    /** Unpublished rows that passed the age limit and were dropped (or would be). */
    public function unpublishedRowsDropped(): int
    {
        return array_sum($this->unpublished);
    }
}
