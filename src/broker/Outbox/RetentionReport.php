<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use DateTimeImmutable;

/** Outcome of one OutboxRetention::purge() run. */
final readonly class RetentionReport
{
    /**
     * @param list<string> $dropped storage units dropped (or, on a dry run, that would be)
     * @param array<string, int> $unpublished dropped units that held unpublished rows (drop-unpublished mode only): name => row count
     * @param array<string, int> $kept expired units kept because they hold unpublished rows: name => row count
     */
    public function __construct(
        public DateTimeImmutable $cutoff,
        public array $dropped,
        public array $unpublished,
        public array $kept = [],
    ) {}

    /** Unpublished rows that passed the age limit and were dropped (or would be). */
    public function unpublishedRowsDropped(): int
    {
        return array_sum($this->unpublished);
    }

    /** Unpublished rows of the expired units that were kept. */
    public function unpublishedRowsKept(): int
    {
        return array_sum($this->kept);
    }

    /** True when at least one expired unit was kept because of unpublished rows. */
    public function hasKept(): bool
    {
        return $this->kept !== [];
    }
}
