<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Time\EpochMillis;

/**
 * In-memory {@see DeduplicationStore}. An entry is the pair of the message id
 * and the consumer, so the same message id is new for another consumer. The
 * time of an entry is the time of acquire() on the clock of symfony/clock
 * (use MockClock to age entries). Pass an {@see InMemoryTransaction} to make
 * the entries roll back with it.
 */
final class InMemoryDeduplicationStore implements DeduplicationStore, TransactionParticipant
{
    /** @var array<string, int> "consumer NUL message id" => epoch ms of acquire() */
    private array $entries = [];

    public function __construct(?InMemoryTransaction $transaction = null)
    {
        $transaction?->enlist($this);
    }

    public function acquire(IncomingMessage $message, string $consumer): bool
    {
        $key = $consumer."\0".$message->messageId;
        if (isset($this->entries[$key])) {
            return false;
        }
        $this->entries[$key] = EpochMillis::now();

        return true;
    }

    public function cleanup(int $beforeEpochMs): int
    {
        $before = count($this->entries);
        $this->entries = array_filter(
            $this->entries,
            static fn (int $createdAt): bool => $createdAt >= $beforeEpochMs,
        );

        return $before - count($this->entries);
    }

    public function countOlderThan(int $beforeEpochMs): int
    {
        return count(array_filter(
            $this->entries,
            static fn (int $createdAt): bool => $createdAt < $beforeEpochMs,
        ));
    }

    public function snapshot(): mixed
    {
        return $this->entries;
    }

    public function restore(mixed $snapshot): void
    {
        /** @var array<string, int> $snapshot */
        $this->entries = $snapshot;
    }
}
