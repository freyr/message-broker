<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Closure;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;

/**
 * Delegates to an inner store; reports each markReplayed() call to $log and
 * can force its result (a race: another process set the mark first).
 */
final class RecordingDeadLetterStore implements DeadLetterStore
{
    /** @var bool|null the forced result of markReplayed(); null delegates */
    public ?bool $markReplayedResult = null;

    /** @param (Closure(string): void)|null $log */
    public function __construct(
        private readonly DeadLetterStore $inner,
        private readonly ?Closure $log = null,
    ) {}

    public function store(DeadLetter $deadLetter): void
    {
        $this->inner->store($deadLetter);
    }

    public function find(string $id): ?DeadLetter
    {
        return $this->inner->find($id);
    }

    public function list(DeadLetterFilter $filter, int $limit = 100, int $offset = 0): array
    {
        return $this->inner->list($filter, $limit, $offset);
    }

    public function count(DeadLetterFilter $filter): int
    {
        return $this->inner->count($filter);
    }

    public function ids(DeadLetterFilter $filter, int $limit): array
    {
        return $this->inner->ids($filter, $limit);
    }

    public function stats(DeadLetterFilter $filter): array
    {
        return $this->inner->stats($filter);
    }

    public function markReplayed(string $id): bool
    {
        if ($this->log !== null) {
            ($this->log)('markReplayed');
        }

        return $this->markReplayedResult ?? $this->inner->markReplayed($id);
    }

    public function purge(DeadLetterFilter $filter): int
    {
        return $this->inner->purge($filter);
    }
}
