<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\FailingRow;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;
use LogicException;

/**
 * In-memory outbox store that implements the drainClaimed() contract: it
 * claims unpublished, due rows of a lane (oldest first, up to the limit),
 * passes them to the callback, and applies the outcome (published rows are
 * marked, retried rows get attempts + 1 and the new available_at). When the
 * callback throws, nothing is applied. Rows of an open claim are skipped by a
 * nested claim of the same store, as a database skips locked rows. Pass an
 * {@see InMemoryTransaction} to make inserts roll back with it.
 *
 * Rows are keyed by (id, createdAt), as the primary key of the outbox table:
 * a replay of a published message is a second row with the same id. An id in
 * the outcome applies to each claimed row with that id. The helpers that take
 * only an id (row(), isPublished()) address the row with the latest createdAt.
 *
 * abandon() marks each open row of an id; the claim, failing() and the outcome
 * writes skip an abandoned row. An abandoned row is not published, so
 * unpublishedIds() still lists it. A roll back restores the marks too.
 */
final class InMemoryOutboxStore implements OutboxStore, TransactionParticipant
{
    /** @var list<int> sizes of the claims that were passed to the callback */
    public array $claimSizes = [];

    public int $drainCalls = 0;

    /** @var (callable(int): void)|null called at the start of each drainClaimed() with the call number */
    public $onDrain;

    /** @var array<string, OutboxRecord> row key (id and createdAt) => current state */
    private array $rows = [];

    /** @var array<string, true> row keys */
    private array $published = [];

    /** @var array<string, true> row keys of the abandoned rows */
    private array $abandoned = [];

    /** @var array<string, true> row keys of the claims that are open now */
    private array $claiming = [];

    public function __construct(?InMemoryTransaction $transaction = null)
    {
        $transaction?->enlist($this);
    }

    public function insert(OutboxRecord $record): void
    {
        $this->rows[self::key($record)] = $record;
    }

    public function drainClaimed(string $lane, int $limit, callable $publish): int
    {
        ++$this->drainCalls;
        if ($this->onDrain !== null) {
            ($this->onDrain)($this->drainCalls);
        }

        $now = EpochMillis::now();
        /** @var array<string, OutboxRecord> $claimed row key => row */
        $claimed = [];
        foreach ($this->rows as $key => $row) {
            if (count($claimed) >= $limit) {
                break;
            }
            if ($row->lane === $lane
                && !isset($this->published[$key])
                && !isset($this->abandoned[$key])
                && !isset($this->claiming[$key])
                && ($row->availableAt ?? 0) <= $now) {
                $claimed[$key] = $row;
            }
        }
        if ($claimed === []) {
            return 0;
        }
        $this->claimSizes[] = count($claimed);

        $claimedIds = array_map(static fn (OutboxRecord $r): string => $r->id, array_values($claimed));
        foreach (array_keys($claimed) as $key) {
            $this->claiming[$key] = true;
        }

        try {
            /** @var ClaimOutcome $outcome */
            $outcome = $publish(array_values($claimed));

            $retryIds = array_map('strval', array_keys($outcome->retryAtMs));
            $overlap = array_intersect($outcome->publishedIds, $retryIds);
            $foreign = array_diff([...$outcome->publishedIds, ...$retryIds], $claimedIds);
            if ($overlap !== [] || $foreign !== []) {
                throw new LogicException('The outcome breaks the drainClaimed() contract.');
            }
        } finally {
            foreach (array_keys($claimed) as $key) {
                unset($this->claiming[$key]);
            }
        }

        // An id of the outcome applies to each claimed row with that id, as the
        // UPDATE ... WHERE id = ANY(...) of the database does. A row that was
        // abandoned during the claim keeps its state.
        foreach ($claimed as $key => $row) {
            if (isset($this->abandoned[$key])) {
                continue;
            }
            if (in_array($row->id, $outcome->publishedIds, true)) {
                $this->published[$key] = true;
            }
            $availableAt = $outcome->retryAtMs[$row->id] ?? null;
            if ($availableAt !== null) {
                $this->rows[$key] = new OutboxRecord(
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
        }

        return count($outcome->publishedIds);
    }

    public function abandon(string $id): int
    {
        $count = 0;
        foreach ($this->rows as $key => $row) {
            if ($row->id === $id && !isset($this->published[$key]) && !isset($this->abandoned[$key])) {
                $this->abandoned[$key] = true;
                ++$count;
            }
        }

        return $count;
    }

    public function failing(string $lane, int $minAttempts, int $limit): array
    {
        if ($minAttempts < 1 || $limit < 1) {
            throw new InvalidArgumentException("minAttempts and limit must be 1 or more, got {$minAttempts} and {$limit}");
        }
        $rows = array_filter(
            $this->rows,
            fn (OutboxRecord $row, string $key): bool => $row->lane === $lane
                && $row->attempts >= $minAttempts
                && !isset($this->published[$key])
                && !isset($this->abandoned[$key]),
            ARRAY_FILTER_USE_BOTH,
        );
        usort($rows, static fn (OutboxRecord $a, OutboxRecord $b): int => [$b->attempts, $a->createdAt] <=> [$a->attempts, $b->createdAt]);

        return array_map(
            static fn (OutboxRecord $row): FailingRow => new FailingRow(
                $row->id,
                $row->lane,
                $row->messageName,
                $row->attempts,
                $row->createdAt,
                $row->availableAt ?? $row->createdAt,
                strlen($row->body),
            ),
            array_slice($rows, 0, $limit),
        );
    }

    /** Whether the row of this id with the latest createdAt is abandoned. False for an unknown id. */
    public function isAbandoned(string $id): bool
    {
        $key = $this->latestKey($id);

        return $key !== null && isset($this->abandoned[$key]);
    }

    /** Whether the row of this id with the latest createdAt is published. False for an unknown id. */
    public function isPublished(string $id): bool
    {
        $key = $this->latestKey($id);

        return $key !== null && isset($this->published[$key]);
    }

    /** The row of this id with the latest createdAt. */
    public function row(string $id): OutboxRecord
    {
        $key = $this->latestKey($id) ?? throw new LogicException("Unknown row {$id}");

        return $this->rows[$key];
    }

    /** @return list<string> ids of the rows that are not published, each id once, in the order of the first insert */
    public function unpublishedIds(): array
    {
        $ids = [];
        foreach ($this->rows as $key => $row) {
            if (!isset($this->published[$key])) {
                $ids[$row->id] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }

    public function snapshot(): mixed
    {
        return [$this->rows, $this->published, $this->abandoned];
    }

    public function restore(mixed $snapshot): void
    {
        /** @var array{array<string, OutboxRecord>, array<string, true>, array<string, true>} $snapshot */
        [$this->rows, $this->published, $this->abandoned] = $snapshot;
    }

    /** The primary key of outbox_messages is (id, created_at): a replay adds a second row with the same id. */
    private static function key(OutboxRecord $record): string
    {
        return $record->id.'@'.$record->createdAt;
    }

    private function latestKey(string $id): ?string
    {
        $latest = null;
        foreach ($this->rows as $key => $row) {
            if ($row->id === $id && ($latest === null || $row->createdAt > $this->rows[$latest]->createdAt)) {
                $latest = $key;
            }
        }

        return $latest;
    }
}
