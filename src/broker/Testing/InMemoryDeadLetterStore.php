<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;

/**
 * In-memory DeadLetterStore that applies the contract filters (list, count,
 * purge) and records the arguments of every call, so a command test can
 * assert what the command asked for. Pass an {@see InMemoryTransaction} to
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

    public function add(DeadLetter $deadLetter): void
    {
        $this->items[$deadLetter->id] = $deadLetter;
    }

    public function store(DeadLetter $deadLetter): void
    {
        $this->add($deadLetter);
    }

    public function find(string $id): ?DeadLetter
    {
        return $this->items[$id] ?? null;
    }

    public function list(
        ?string $messageName = null,
        ?string $source = null,
        ?int $sinceMs = null,
        int $limit = 100,
        int $offset = 0,
        ?bool $replayed = null,
    ): array {
        $this->calls[] = [
            'method' => 'list',
            'args' => compact('messageName', 'source', 'sinceMs', 'limit', 'offset', 'replayed'),
        ];

        return array_slice($this->matching($messageName, $source, $sinceMs, $replayed), $offset, $limit);
    }

    public function count(
        ?string $messageName = null,
        ?string $source = null,
        ?int $sinceMs = null,
        ?bool $replayed = null,
    ): int {
        $this->calls[] = [
            'method' => 'count',
            'args' => compact('messageName', 'source', 'sinceMs', 'replayed'),
        ];

        return count($this->matching($messageName, $source, $sinceMs, $replayed));
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

    public function purge(?string $messageName = null, ?string $source = null, ?int $olderThanMs = null): int
    {
        $this->calls[] = [
            'method' => 'purge',
            'args' => compact('messageName', 'source', 'olderThanMs'),
        ];

        $before = count($this->items);
        $this->items = array_filter(
            $this->items,
            static fn (DeadLetter $d): bool => !(($messageName === null || $d->messageName === $messageName)
                && ($source === null || $d->source === $source)
                && ($olderThanMs === null || $d->failedAt < $olderThanMs)),
        );

        return $before - count($this->items);
    }

    /** @return list<array<string, mixed>> the recorded arguments of every call to one method */
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

    /** @return list<DeadLetter> */
    private function matching(?string $messageName, ?string $source, ?int $sinceMs, ?bool $replayed): array
    {
        $matching = array_filter(
            $this->items,
            static fn (DeadLetter $d): bool => ($messageName === null || $d->messageName === $messageName)
                && ($source === null || $d->source === $source)
                && ($sinceMs === null || $d->failedAt >= $sinceMs)
                && ($replayed === null || ($d->replayedAt !== null) === $replayed),
        );
        usort(
            $matching,
            static fn (DeadLetter $a, DeadLetter $b): int => [$b->failedAt, $b->id] <=> [$a->failedAt, $a->id],
        );

        return $matching;
    }
}
