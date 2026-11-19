<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStat;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;

/**
 * In-memory DeadLetterStore that applies the contract filter (list, count,
 * ids, stats, purge) and records the arguments of every call (the filter
 * object as 'filter'), so a command test can assert what the command asked
 * for. Pass an {@see InMemoryTransaction} to
 * make the changes roll back with it.
 */
final class InMemoryDeadLetterStore implements DeadLetterStore, TransactionParticipant
{
    /** @var list<array{method: string, args: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, DeadLetter> */
    private array $items = [];

    public function __construct(?InMemoryTransaction $transaction = null)
    {
        $transaction?->enlist($this);
    }

    /** A seed hook: stores the object as it is, with no upsert (a test can seed two open rows of one pair). */
    public function add(DeadLetter $deadLetter): void
    {
        $this->items[$deadLetter->id] = $deadLetter;
    }

    /**
     * The rule of the port: nothing changes when an item has the id (open or
     * replayed). Otherwise the open item of the same message id and source
     * keeps its id and gets the data of the new failure; with no open item,
     * the dead letter is added.
     */
    public function store(DeadLetter $deadLetter): void
    {
        if (isset($this->items[$deadLetter->id])) {
            return;
        }
        foreach ($this->items as $id => $item) {
            if ($item->replayedAt === null
                && $item->messageId === $deadLetter->messageId
                && $item->source === $deadLetter->source) {
                $this->items[$id] = new DeadLetter(
                    id: $item->id,
                    source: $item->source,
                    messageId: $item->messageId,
                    messageName: $deadLetter->messageName,
                    body: $deadLetter->body,
                    headers: $deadLetter->headers,
                    errorClass: $deadLetter->errorClass,
                    errorMessage: $deadLetter->errorMessage,
                    errorTrace: $deadLetter->errorTrace,
                    attempts: $deadLetter->attempts,
                    failedAt: $deadLetter->failedAt,
                    replayable: $deadLetter->replayable,
                );

                return;
            }
        }
        $this->add($deadLetter);
    }

    public function find(string $id): ?DeadLetter
    {
        return $this->items[$id] ?? null;
    }

    public function list(DeadLetterFilter $filter, int $limit = 100, int $offset = 0): array
    {
        $this->calls[] = [
            'method' => 'list',
            'args' => compact('filter', 'limit', 'offset'),
        ];

        return array_slice(array_reverse($this->matching($filter)), $offset, $limit);
    }

    public function count(DeadLetterFilter $filter): int
    {
        $this->calls[] = [
            'method' => 'count',
            'args' => compact('filter'),
        ];

        return count($this->matching($filter));
    }

    public function ids(DeadLetterFilter $filter, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("limit must be at least 1, got {$limit}");
        }
        $this->calls[] = [
            'method' => 'ids',
            'args' => compact('filter', 'limit'),
        ];

        return array_map(
            static fn (DeadLetter $d): string => $d->id,
            array_slice($this->matching($filter), 0, $limit),
        );
    }

    public function stats(DeadLetterFilter $filter): array
    {
        $this->calls[] = [
            'method' => 'stats',
            'args' => compact('filter'),
        ];

        /** @var array<string, DeadLetterStat> $groups */
        $groups = [];
        foreach ($this->matching($filter) as $d) {
            $key = json_encode([$d->messageName, $d->source, $d->errorClass], JSON_THROW_ON_ERROR);
            $group = $groups[$key] ?? null;
            // matching() is oldest first: the first row of a group is its oldest, the last its newest
            $groups[$key] = new DeadLetterStat(
                $d->messageName,
                $d->source,
                $d->errorClass,
                ($group->count ?? 0) + 1,
                $group->oldestFailedAt ?? $d->failedAt,
                $d->failedAt,
            );
        }
        $stats = array_values($groups);
        usort(
            $stats,
            static fn (DeadLetterStat $a, DeadLetterStat $b): int => [$b->count, $a->messageName, $a->source, $a->errorClass]
                <=> [$a->count, $b->messageName, $b->source, $b->errorClass],
        );

        return $stats;
    }

    public function markReplayed(string $id): void
    {
        $item = $this->items[$id] ?? null;
        if ($item === null) {
            return;
        }
        $this->items[$id] = new DeadLetter(
            $item->id,
            $item->source,
            $item->messageId,
            $item->messageName,
            $item->body,
            $item->headers,
            $item->errorClass,
            $item->errorMessage,
            $item->errorTrace,
            $item->attempts,
            $item->failedAt,
            EpochMillis::now(),
            $item->replayable,
        );
    }

    public function purge(DeadLetterFilter $filter): int
    {
        $this->calls[] = [
            'method' => 'purge',
            'args' => compact('filter'),
        ];

        $before = count($this->items);
        $this->items = array_filter($this->items, static fn (DeadLetter $d): bool => !self::matches($d, $filter));

        return $before - count($this->items);
    }

    /**
     * @return list<array<string, mixed>> the recorded arguments of every call to one method: the
     *                                    filter object as 'filter', and 'limit' and 'offset' where the
     *                                    method takes them
     */
    public function callsOf(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['args'],
            array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method),
        ));
    }

    public function snapshot(): mixed
    {
        return $this->items;
    }

    public function restore(mixed $snapshot): void
    {
        /** @var array<string, DeadLetter> $snapshot */
        $this->items = $snapshot;
    }

    /** @return list<DeadLetter> the matching items, oldest first (failedAt, then id ascending) */
    private function matching(DeadLetterFilter $filter): array
    {
        $matching = array_values(array_filter(
            $this->items,
            static fn (DeadLetter $d): bool => self::matches($d, $filter),
        ));
        usort(
            $matching,
            static fn (DeadLetter $a, DeadLetter $b): int => [$a->failedAt, $a->id] <=> [$b->failedAt, $b->id],
        );

        return $matching;
    }

    private static function matches(DeadLetter $d, DeadLetterFilter $filter): bool
    {
        return ($filter->messageName === null || $d->messageName === $filter->messageName)
            && ($filter->source === null || $d->source === $filter->source)
            && ($filter->errorClass === null || $d->errorClass === $filter->errorClass)
            && ($filter->messageId === null || $d->messageId === $filter->messageId)
            && ($filter->failedFromMs === null || $d->failedAt >= $filter->failedFromMs)
            && ($filter->failedUntilMs === null || $d->failedAt < $filter->failedUntilMs)
            && ($filter->minAttempts === null || $d->attempts >= $filter->minAttempts)
            && ($filter->replayed === null || ($d->replayedAt !== null) === $filter->replayed)
            && ($filter->replayable === null || $d->replayable === $filter->replayable);
    }
}
