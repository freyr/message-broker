<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

/**
 * An in-memory store that takes part in an {@see InMemoryTransaction}: the
 * transaction asks it for a snapshot at begin() and hands the snapshot back at
 * rollBack().
 */
interface TransactionParticipant
{
    /** The complete state of the store. A copy: later changes must not alter it. */
    public function snapshot(): mixed;

    /** Replace the state of the store with a snapshot that snapshot() returned. */
    public function restore(mixed $snapshot): void;
}
