<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Storage\Transaction;
use LogicException;

/**
 * In-memory {@see Transaction}. The in-memory stores that take part in it
 * (pass the transaction to their constructor) go back to the state of begin()
 * when the transaction rolls back, so a deduplication row that a rolled-back
 * delivery acquired is gone, as it is in a database.
 *
 * Design: the transaction keeps the list of its participants. begin() takes
 * one snapshot of each; commit() drops the snapshots; rollBack() restores
 * them. There is no isolation between transactions and no nesting: begin()
 * during an active transaction, and commit() or rollBack() without one, throw
 * a LogicException (the same cases fail on a real connection).
 */
final class InMemoryTransaction implements Transaction
{
    /** @var list<TransactionParticipant> */
    private array $participants = [];

    /** @var list<mixed>|null one snapshot per participant while a transaction is active */
    private ?array $snapshots = null;

    public function enlist(TransactionParticipant $participant): void
    {
        if ($this->snapshots !== null) {
            throw new LogicException('A store cannot join a transaction that is active.');
        }
        $this->participants[] = $participant;
    }

    public function isActive(): bool
    {
        return $this->snapshots !== null;
    }

    public function begin(): void
    {
        if ($this->snapshots !== null) {
            throw new LogicException('A transaction is already active.');
        }
        $this->snapshots = array_map(
            static fn (TransactionParticipant $participant): mixed => $participant->snapshot(),
            $this->participants,
        );
    }

    public function commit(): void
    {
        if ($this->snapshots === null) {
            throw new LogicException('There is no active transaction to commit.');
        }
        $this->snapshots = null;
    }

    public function rollBack(): void
    {
        if ($this->snapshots === null) {
            throw new LogicException('There is no active transaction to roll back.');
        }
        foreach ($this->participants as $position => $participant) {
            $participant->restore($this->snapshots[$position]);
        }
        $this->snapshots = null;
    }
}
