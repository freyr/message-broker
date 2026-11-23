<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\ClaimedDelivery;
use Freyr\MessageBroker\Webhook\DeliveryCompletionKind;
use Freyr\MessageBroker\Webhook\DeliveryDeadLetter;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use LogicException;

/**
 * In-memory delivery store that implements the contract of
 * {@see DeliveryStore}: the claim with a lease (available_at = now + lease),
 * the row limit for each lane and the total, the due counts, the lease check
 * of complete(), the abandon of old rows and their count. The dead letters of
 * complete() and abandon() go to an
 * {@see InMemoryDeadLetterStore} through store(), which keeps one open row
 * for each pair of message ID and source.
 *
 * Rows are keyed by id. The claim takes the lanes in the order of the keys,
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

    /** @var list<array{limitForEachLane: array<array-key, int>, total: int, leaseMs: int}> the arguments of each claim() */
    public array $claims = [];

    /** @var list<array{lanes: list<string>, cap: int}> the arguments of each dueCounts() */
    public array $dueCountCalls = [];

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

    public function claim(array $limitForEachLane, int $total, int $leaseMs): array
    {
        ++$this->claimCalls;
        $this->claims[] = compact('limitForEachLane', 'total', 'leaseMs');
        if ($this->onClaim !== null) {
            ($this->onClaim)($this->claimCalls);
        }

        $now = EpochMillis::now();
        $claimed = [];
        foreach ($limitForEachLane as $lane => $limit) {
            $due = $this->due((string) $lane, $now);
            foreach (array_slice($due, 0, $limit) as $row) {
                if (count($claimed) >= $total) {
                    break 2;
                }
                $claimed[] = $this->lease($row, $now + $leaseMs);
            }
        }

        return $claimed;
    }

    public function dueCounts(array $lanes, int $cap): array
    {
        $this->dueCountCalls[] = compact('lanes', 'cap');
        $now = EpochMillis::now();
        $counts = [];
        foreach ($lanes as $lane) {
            $counts[$lane] = min($cap, count($this->due($lane, $now)));
        }

        return $counts;
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

    public function abandon(int $before, ?array $lanes, int $limit): int
    {
        $now = EpochMillis::now();
        $count = 0;
        foreach ($this->rows as $row) {
            if ($count >= $limit) {
                break;
            }
            if (isset($this->finished[$row->id]) || $row->createdAt >= $before || ($lanes !== null && !in_array($row->lane, $lanes, true))) {
                continue;
            }
            $this->deadLetters->store(DeliveryDeadLetter::abandoned($row, $before, $now));
            $this->finished[$row->id] = DeliveryCompletionKind::Dead;
            ++$count;
        }

        return $count;
    }

    public function countUnfinished(int $before, ?array $lanes): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            if (isset($this->finished[$row->id]) || $row->createdAt >= $before || ($lanes !== null && !in_array($row->lane, $lanes, true))) {
                continue;
            }
            $counts[$row->lane] = ($counts[$row->lane] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /** The dead letter store that complete() and abandon() write to. */
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

    /** @return list<DeliveryRecord> the due, unfinished rows of the lane, in claim order */
    private function due(string $lane, int $now): array
    {
        $due = array_values(array_filter(
            $this->rows,
            fn (DeliveryRecord $row): bool => $row->lane === $lane && $this->isDue($row, $now),
        ));
        // usort keeps the order of equal elements: the insert order breaks a tie.
        usort($due, static fn (DeliveryRecord $a, DeliveryRecord $b): int => self::dueAt($a) <=> self::dueAt($b));

        return $due;
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
