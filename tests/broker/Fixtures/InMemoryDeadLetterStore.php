<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;

/**
 * In-memory DeadLetterStore that applies the contract filters and records the
 * arguments of every call, so a command test can assert what the command asked for.
 */
final class InMemoryDeadLetterStore implements DeadLetterStore
{
    public int $purgeResult = 0;

    /** @var list<array{method: string, args: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, DeadLetter> */
    private array $items = [];

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
        $item = $this->items[$id];
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
            $item->failedAt + 1,
        );
    }

    public function purge(?string $messageName = null, ?string $source = null, ?int $olderThanMs = null): int
    {
        $this->calls[] = [
            'method' => 'purge',
            'args' => compact('messageName', 'source', 'olderThanMs'),
        ];

        return $this->purgeResult;
    }

    /** @return list<array<string, mixed>> the recorded arguments of every call to one method */
    public function callsOf(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['args'],
            array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method),
        ));
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
        usort($matching, static fn (DeadLetter $a, DeadLetter $b): int => $b->failedAt <=> $a->failedAt);

        return $matching;
    }
}
