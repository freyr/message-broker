<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\ClaimedDelivery;
use Freyr\MessageBroker\Webhook\DeliveryCompletionKind;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use LogicException;

/**
 * In-memory delivery store that implements the contract of
 * {@see DeliveryStore}: the claim with a lease (available_at = now + lease),
 * the row limit for each lane and the total, the lease check of complete(),
 * and the claim of expired rows. The dead letters of complete() go to an
 * {@see InMemoryDeadLetterStore} through store(), which keeps one open row
 * for each pair of message ID and source.
 *
 * Rows are keyed by id. The claim takes the lanes in the order of the list,
 * and the rows of a lane by available_at (a new row: created_at), then in the
 * order of the insert. The time comes from symfony/clock through
 * {@see EpochMillis}.
 *
 * Pass an {@see InMemoryTransaction} to make inserts roll back with it; pass
 * the same transaction to the dead letter store to make its rows roll back
 * too.
 */
final class InMemoryDeliveryStore implements DeliveryStore, TransactionParticipant
{
    public int $claimCalls = 0;

    /** @var list<array{lanes: list<string>, limitForEachLane: int, total: int, leaseMs: int}> the arguments of each claim() */
    public array $claims = [];

    /** @var list<array{before: int, limit: int, leaseMs: int}> the arguments of each claimExpired() */
    public array $expiredClaims = [];

    /** @var (callable(int): void)|null called at the start of each claim() with the call number */
    public $onClaim;

    /** @var array<string, DeliveryRecord> row id => current state */
    private array $rows = [];

    /** @var array<string, DeliveryCompletionKind> row id => final state (Delivered or Dead) */
    private array $finished = [];

    private readonly InMemoryDeadLetterStore $deadLetters;

    public function __construct(?InMemoryDeadLetterStore $deadLetters = null, ?InMemoryTransaction $transaction = null)
    {
        $this->deadLetters = $deadLetters ?? new InMemoryDeadLetterStore();
        $transaction?->enlist($this);
    }

    public function insert(array $records): void
    {
        foreach ($records as $record) {
            $this->rows[$record->id] = $record;
        }
    }

    public function claim(array $lanes, int $limitForEachLane, int $total, int $leaseMs): array
    {
        ++$this->claimCalls;
        $this->claims[] = compact('lanes', 'limitForEachLane', 'total', 'leaseMs');
        if ($this->onClaim !== null) {
            ($this->onClaim)($this->claimCalls);
        }

        $now = EpochMillis::now();
        $claimed = [];
        foreach (array_unique($lanes) as $lane) {
            $due = array_filter(
                $this->rows,
                fn (DeliveryRecord $row): bool => $row->lane === $lane && $this->isDue($row, $now),
            );
            // usort keeps the order of equal elements: the insert order breaks a tie.
            usort($due, static fn (DeliveryRecord $a, DeliveryRecord $b): int => self::dueAt($a) <=> self::dueAt($b));
            foreach (array_slice($due, 0, $limitForEachLane) as $row) {
                if (count($claimed) >= $total) {
                    break 2;
                }
                $claimed[] = $this->lease($row, $now + $leaseMs);
            }
        }

        return $claimed;
    }

    public function complete(array $outcomes): array
    {
        $changed = [];
        foreach ($outcomes as $outcome) {
            $claimed = $outcome->claimed->delivery;
            $row = $this->rows[$claimed->id] ?? null;
            if ($row === null || isset($this->finished[$row->id]) || $row->availableAt !== $outcome->claimed->lease) {
                continue; // the lease check
            }
            $availableAt = $row->availableAt;
            switch ($outcome->kind) {
                case DeliveryCompletionKind::Delivered:
                    $this->finished[$row->id] = DeliveryCompletionKind::Delivered;
                    break;
                case DeliveryCompletionKind::Retry:
                    $availableAt = $outcome->availableAt;
                    break;
                case DeliveryCompletionKind::Dead:
                    $this->deadLetters->store($outcome->deadLetter ?? throw new LogicException('A dead outcome has a dead letter.'));
                    $this->finished[$row->id] = DeliveryCompletionKind::Dead;
                    break;
            }
            $this->rows[$row->id] = self::with($row, $outcome->attempts, $availableAt);
            $changed[] = $row->id;
        }

        return $changed;
    }

    public function claimExpired(int $before, int $limit, int $leaseMs): array
    {
        $this->expiredClaims[] = compact('before', 'limit', 'leaseMs');
        $now = EpochMillis::now();
        $expired = array_filter(
            $this->rows,
            fn (DeliveryRecord $row): bool => $row->createdAt < $before && $this->isDue($row, $now),
        );
        usort($expired, static fn (DeliveryRecord $a, DeliveryRecord $b): int => $a->createdAt <=> $b->createdAt);

        return array_map(
            fn (DeliveryRecord $row): ClaimedDelivery => $this->lease($row, $now + $leaseMs),
            array_slice($expired, 0, $limit),
        );
    }

    /** The dead letter store that complete() writes to. */
    public function deadLetters(): InMemoryDeadLetterStore
    {
        return $this->deadLetters;
    }

    /** The current state of a row. */
    public function row(string $id): DeliveryRecord
    {
        return $this->rows[$id] ?? throw new LogicException("Unknown row {$id}");
    }

    /** The final state of a row: Delivered or Dead; null while the row is unfinished. */
    public function outcome(string $id): ?DeliveryCompletionKind
    {
        $this->row($id);

        return $this->finished[$id] ?? null;
    }

    /** @return list<DeliveryRecord> all rows, in the order of the first insert */
    public function records(): array
    {
        return array_values($this->rows);
    }

    public function snapshot(): mixed
    {
        return [$this->rows, $this->finished];
    }

    public function restore(mixed $snapshot): void
    {
        /** @var array{array<string, DeliveryRecord>, array<string, DeliveryCompletionKind>} $snapshot */
        [$this->rows, $this->finished] = $snapshot;
    }

    private function isDue(DeliveryRecord $row, int $now): bool
    {
        // A row with no available_at is due now (insert path).
        return !isset($this->finished[$row->id]) && ($row->availableAt ?? 0) <= $now;
    }

    private static function dueAt(DeliveryRecord $row): int
    {
        return $row->availableAt ?? $row->createdAt;
    }

    private function lease(DeliveryRecord $row, int $lease): ClaimedDelivery
    {
        $leased = self::with($row, $row->attempts, $lease);
        $this->rows[$row->id] = $leased;

        return new ClaimedDelivery($leased, $lease);
    }

    private static function with(DeliveryRecord $row, int $attempts, ?int $availableAt): DeliveryRecord
    {
        return new DeliveryRecord(
            $row->id,
            $row->messageId,
            $row->lane,
            $row->messageName,
            $row->body,
            $row->headers,
            $row->createdAt,
            $attempts,
            $availableAt,
        );
    }
}
