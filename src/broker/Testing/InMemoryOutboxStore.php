<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use LogicException;

/**
 * In-memory outbox store that implements the drainClaimed() contract: it
 * claims unpublished, due rows of a lane (oldest first, up to the limit),
 * passes them to the callback, and applies the outcome (published rows are
 * marked, retried rows get attempts + 1 and the new available_at). When the
 * callback throws, nothing is applied. Rows of an open claim are skipped by a
 * nested claim of the same store, as a database skips locked rows. Pass an
 * {@see InMemoryTransaction} to make inserts roll back with it.
 */
final class InMemoryOutboxStore implements OutboxStore, TransactionParticipant
{
    /** @var list<int> sizes of the claims that were passed to the callback */
    public array $claimSizes = [];

    public int $drainCalls = 0;

    /** @var (callable(int): void)|null called at the start of each drainClaimed() with the call number */
    public $onDrain;

    /** @var array<string, OutboxRecord> row id => current state */
    private array $rows = [];

    /** @var array<string, true> */
    private array $published = [];

    /** @var array<string, true> rows of the claims that are open now */
    private array $claiming = [];

    public function __construct(?InMemoryTransaction $transaction = null)
    {
        $transaction?->enlist($this);
    }

    public function insert(OutboxRecord $record): void
    {
        $this->rows[$record->id] = $record;
    }

    public function drainClaimed(string $lane, int $limit, callable $publish): int
    {
        ++$this->drainCalls;
        if ($this->onDrain !== null) {
            ($this->onDrain)($this->drainCalls);
        }

        $now = EpochMillis::now();
        $claimed = [];
        foreach ($this->rows as $id => $row) {
            if (count($claimed) >= $limit) {
                break;
            }
            if ($row->lane === $lane
                && !isset($this->published[$id])
                && !isset($this->claiming[$id])
                && ($row->availableAt ?? 0) <= $now) {
                $claimed[] = $row;
            }
        }
        if ($claimed === []) {
            return 0;
        }
        $this->claimSizes[] = count($claimed);

        $claimedIds = array_map(static fn (OutboxRecord $r): string => $r->id, $claimed);
        foreach ($claimedIds as $id) {
            $this->claiming[$id] = true;
        }

        try {
            /** @var ClaimOutcome $outcome */
            $outcome = $publish($claimed);

            $retryIds = array_map('strval', array_keys($outcome->retryAtMs));
            $overlap = array_intersect($outcome->publishedIds, $retryIds);
            $foreign = array_diff([...$outcome->publishedIds, ...$retryIds], $claimedIds);
            if ($overlap !== [] || $foreign !== []) {
                throw new LogicException('The outcome breaks the drainClaimed() contract.');
            }
        } finally {
            foreach ($claimedIds as $id) {
                unset($this->claiming[$id]);
            }
        }

        foreach ($outcome->publishedIds as $id) {
            $this->published[$id] = true;
        }
        foreach ($outcome->retryAtMs as $id => $availableAt) {
            $row = $this->rows[$id];
            $this->rows[$id] = new OutboxRecord(
                $row->id,
                $row->lane,
                $row->messageName,
                $row->body,
                $row->headers,
                $row->createdAt,
                $row->attempts + 1,
                $availableAt,
            );
        }

        return count($outcome->publishedIds);
    }

    public function isPublished(string $id): bool
    {
        return isset($this->published[$id]);
    }

    public function row(string $id): OutboxRecord
    {
        return $this->rows[$id] ?? throw new LogicException("Unknown row {$id}");
    }

    /** @return list<string> ids of the rows that are not published */
    public function unpublishedIds(): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($this->rows)),
            fn (string $id): bool => !isset($this->published[$id]),
        ));
    }

    public function snapshot(): mixed
    {
        return [$this->rows, $this->published];
    }

    public function restore(mixed $snapshot): void
    {
        /** @var array{array<string, OutboxRecord>, array<string, true>} $snapshot */
        [$this->rows, $this->published] = $snapshot;
    }
}
