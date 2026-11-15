<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Contract tests of the {@see OutboxStore} port. An adapter extends this class,
 * returns its store from createStore(), and inherits the tests: they prove that
 * the store keeps the contract of the port. The tests use the port only: no SQL,
 * no broker.
 *
 * Rules for the adapter:
 *  - createStore() returns a store that is EMPTY at the start of each test. The
 *    adapter clears its storage in setUp() (or the factory makes a fresh one).
 *  - A row that insert() accepts for a time near now must be accepted: the tests
 *    use created_at values of the last minutes (an adapter that partitions by
 *    day prepares the partitions of today).
 *  - Hook createSecondStore() to enable the tests of two claimers: it returns a
 *    second store that is independent (for a database: its own connection) and
 *    sees the same rows. Without the hook these tests are skipped.
 */
abstract class OutboxStoreContractTestCase extends TestCase
{
    private const string LANE = 'contract';

    private ?OutboxStore $store = null;

    /** A store that is empty at the start of the test. */
    abstract protected function createStore(): OutboxStore;

    /**
     * A second, independent store over the same rows (for a database: a second
     * connection). Return null when the adapter cannot provide it.
     */
    protected function createSecondStore(): ?OutboxStore
    {
        return null;
    }

    public function testClaimedRecordCarriesEveryFieldOfTheInsertedRecord(): void
    {
        // Whole second, plus 123 ms: a store must keep the milliseconds.
        $createdAt = intdiv(EpochMillis::now(), 1000) * 1000 - 5_000 + 123;
        $inserted = new OutboxRecord(
            id: self::newId(),
            lane: self::LANE,
            messageName: 'order.placed',
            body: "\x00\x01binary\xff{\"zażółć\":1}",
            headers: [
                'correlation_id' => 'c-1',
                'attempt' => 3,
                'flag' => true,
                'unicode' => 'zażółć',
                'nested' => [
                    'list' => ['a', 'b'],
                    'map' => [
                        'k' => 'v',
                    ],
                ],
            ],
            createdAt: $createdAt,
            availableAt: $createdAt + 456,
        );
        $this->store()->insert($inserted);

        $claimed = $this->claimAndPublish($this->store(), self::LANE, 10);

        self::assertCount(1, $claimed);
        $record = $claimed[0];
        self::assertSame($inserted->id, $record->id);
        self::assertSame(self::LANE, $record->lane);
        self::assertSame('order.placed', $record->messageName);
        self::assertSame($inserted->body, $record->body, 'the body is bytes: no re-encoding');
        self::assertSame(self::sortKeys($inserted->headers), self::sortKeys($record->headers));
        self::assertSame($createdAt, $record->createdAt, 'the created_at keeps its milliseconds');
        self::assertSame($createdAt + 456, $record->availableAt, 'the available_at keeps its milliseconds');
        self::assertSame(0, $record->attempts, 'a new row has no failed attempt');
    }

    public function testRecordWithoutHeadersComesBackWithAnEmptyHeaderList(): void
    {
        $this->store()->insert($this->record());

        $claimed = $this->claimAndPublish($this->store(), self::LANE, 10);

        self::assertSame([], $claimed[0]->headers);
    }

    public function testRecordWithoutAvailableAtIsDueImmediately(): void
    {
        $this->store()->insert($this->record());

        self::assertCount(1, $this->claimAndPublish($this->store(), self::LANE, 10));
    }

    public function testClaimReturnsAtMostTheLimitAndOnlyDueRowsOfTheAskedLane(): void
    {
        $ours = [$this->record(), $this->record(), $this->record()];
        $other = $this->record(lane: 'contract_other');
        $notDue = $this->record(availableAt: EpochMillis::now() + 60_000);
        foreach ([...$ours, $other, $notDue] as $record) {
            $this->store()->insert($record);
        }
        $ourIds = self::ids($ours);

        $first = $this->claimAndPublish($this->store(), self::LANE, 2);
        $second = $this->claimAndPublish($this->store(), self::LANE, 10);
        $otherLane = $this->claimAndPublish($this->store(), 'contract_other', 10);

        self::assertCount(2, $first, 'at most the limit');
        self::assertCount(1, $second, 'the rest of the due rows of the lane');
        self::assertEqualsCanonicalizing($ourIds, [...self::ids($first), ...self::ids($second)]);
        self::assertSame([$other->id], self::ids($otherLane), 'a lane sees its own rows only');
        self::assertSame([], $this->claimAndPublish($this->store(), self::LANE, 10), 'a row that is not due waits');
    }

    public function testDrainReturnsTheNumberOfPublishedRowsAndPublishedRowsAreNeverClaimedAgain(): void
    {
        $published = $this->record();
        $retried = $this->record();
        $this->store()->insert($published);
        $this->store()->insert($retried);

        $count = $this->store()->drainClaimed(
            self::LANE,
            10,
            static fn (array $claimed): ClaimOutcome => new ClaimOutcome(
                [$published->id],
                [
                    $retried->id => EpochMillis::now() + 60_000,
                ],
            ),
        );

        self::assertSame(1, $count, 'a retried row does not count as published');
        self::assertSame([], $this->claimAndPublish($this->store(), self::LANE, 10));
    }

    public function testAnEmptyClaimReturnsZeroWithoutInvokingTheCallback(): void
    {
        $invoked = false;

        $count = $this->store()->drainClaimed(self::LANE, 10, static function (array $claimed) use (&$invoked): ClaimOutcome {
            $invoked = true;

            return ClaimOutcome::published([]);
        });

        self::assertSame(0, $count);
        self::assertFalse($invoked, 'the callback must not run for an empty claim');
    }

    public function testRetriedRowsComeBackAfterTheirOwnTimeWithOneMoreAttempt(): void
    {
        $soon = $this->record(body: 'soon', headers: [
            'h' => 'v',
        ]);
        $later = $this->record();
        $this->store()->insert($soon);
        $this->store()->insert($later);
        // 300 ms: the claim below must run before this time, also on a loaded machine.
        $soonAt = EpochMillis::now() + 300;
        $laterAt = EpochMillis::now() + 60_000;
        $this->store()->drainClaimed(self::LANE, 10, static fn (array $claimed): ClaimOutcome => ClaimOutcome::retryAll([
            $soon->id => $soonAt,
            $later->id => $laterAt,
        ]));

        self::assertSame([], $this->claimAndPublish($this->store(), self::LANE, 10), 'nothing is due yet');

        self::waitUntil($soonAt);
        $claimed = $this->claimAndRetry($this->store(), self::LANE, 10, EpochMillis::now() + 150);

        self::assertSame([$soon->id], self::ids($claimed), 'only the row whose time came back');
        self::assertSame(1, $claimed[0]->attempts, 'one failed attempt');
        self::assertSame($soonAt, $claimed[0]->availableAt, 'the claimed row shows the time it waited for');
        self::assertSame('soon', $claimed[0]->body, 'a retry keeps the body');
        self::assertSame([
            'h' => 'v',
        ], $claimed[0]->headers, 'a retry keeps the headers');
        self::assertSame($soon->createdAt, $claimed[0]->createdAt, 'a retry keeps created_at');

        self::waitUntil(EpochMillis::now() + 160);
        $again = $this->claimAndPublish($this->store(), self::LANE, 10);

        self::assertSame([$soon->id], self::ids($again), 'the other row still waits');
        self::assertSame(2, $again[0]->attempts, 'each retry adds one attempt');
    }

    public function testExceptionOfTheCallbackLeavesTheRowsClaimable(): void
    {
        $first = $this->record();
        $second = $this->record();
        $this->store()->insert($first);
        $this->store()->insert($second);
        $failure = new RuntimeException('publish failed');

        try {
            $this->store()->drainClaimed(self::LANE, 10, static function (array $claimed) use ($failure): ClaimOutcome {
                throw $failure;
            });
            self::fail('drainClaimed() must let the exception of the callback pass');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        $claimed = $this->claimAndPublish($this->store(), self::LANE, 10);
        self::assertEqualsCanonicalizing([$first->id, $second->id], self::ids($claimed));
        foreach ($claimed as $record) {
            self::assertSame(0, $record->attempts, 'a failed callback is not an attempt');
        }
    }

    public function testConcurrentClaimersNeverReceiveTheSameRowAndNeverBlock(): void
    {
        $second = $this->createSecondStore();
        if ($second === null) {
            self::markTestSkipped('The adapter gives no second store: override createSecondStore() to run the test of two claimers.');
        }
        $rows = [$this->record(), $this->record(), $this->record()];
        foreach ($rows as $row) {
            $this->store()->insert($row);
        }

        $rival = [];
        $firstCount = $this->store()->drainClaimed(
            self::LANE,
            1,
            static function (array $claimed) use ($second, &$rival): ClaimOutcome {
                // This claim is open: the second claimer must skip its row, not wait for it.
                $second->drainClaimed(
                    self::LANE,
                    10,
                    static function (array $inner) use (&$rival): ClaimOutcome {
                        $rival = $inner;

                        return ClaimOutcome::published(self::ids($inner));
                    },
                );

                return ClaimOutcome::published(self::ids($claimed));
            },
        );

        self::assertSame(1, $firstCount);
        self::assertCount(2, $rival, 'the second claimer gets the rows that the open claim does not hold');
        self::assertSame([], $this->claimAndPublish($this->store(), self::LANE, 10), 'every row was claimed once');
    }

    public function testProducerInsertIsNotBlockedByAnOpenClaim(): void
    {
        $second = $this->createSecondStore();
        if ($second === null) {
            self::markTestSkipped('The adapter gives no second store: override createSecondStore() to run the test of an insert during a claim.');
        }
        $claimedRow = $this->record();
        $newRow = $this->record();
        $this->store()->insert($claimedRow);

        $count = $this->store()->drainClaimed(
            self::LANE,
            10,
            static function (array $claimed) use ($second, $newRow): ClaimOutcome {
                $second->insert($newRow); // while the claim is open: must complete, not wait

                return ClaimOutcome::published(self::ids($claimed));
            },
        );

        self::assertSame(1, $count, 'the new row is not part of the open claim');
        self::assertSame(
            [$newRow->id],
            self::ids($this->claimAndPublish($this->store(), self::LANE, 10)),
            'the new row waits for the next claim',
        );
    }

    final protected function store(): OutboxStore
    {
        return $this->store ??= $this->createStore();
    }

    /**
     * A due record of the test lane, with a new id.
     *
     * @param array<string, mixed> $headers
     */
    final protected function record(
        string $lane = self::LANE,
        ?int $availableAt = null,
        string $body = '{}',
        array $headers = [],
    ): OutboxRecord {
        return new OutboxRecord(
            id: self::newId(),
            lane: $lane,
            messageName: 'order.placed',
            body: $body,
            headers: $headers,
            createdAt: EpochMillis::now() - 1_000,
            availableAt: $availableAt,
        );
    }

    /**
     * Claim and publish every claimed row.
     *
     * @return list<OutboxRecord> the claimed records
     */
    private function claimAndPublish(OutboxStore $store, string $lane, int $limit): array
    {
        $claimed = [];
        $store->drainClaimed($lane, $limit, static function (array $records) use (&$claimed): ClaimOutcome {
            $claimed = $records;

            return ClaimOutcome::published(self::ids($records));
        });

        return $claimed;
    }

    /**
     * Claim and retry every claimed row at the given time.
     *
     * @return list<OutboxRecord> the claimed records
     */
    private function claimAndRetry(OutboxStore $store, string $lane, int $limit, int $retryAtMs): array
    {
        $claimed = [];
        $store->drainClaimed($lane, $limit, static function (array $records) use (&$claimed, $retryAtMs): ClaimOutcome {
            $claimed = $records;

            return ClaimOutcome::retryAll(array_fill_keys(self::ids($records), $retryAtMs));
        });

        return $claimed;
    }

    /**
     * @param array<array-key, OutboxRecord> $records
     *
     * @return list<string>
     */
    private static function ids(array $records): array
    {
        return array_values(array_map(static fn (OutboxRecord $record): string => $record->id, $records));
    }

    private static function newId(): string
    {
        return Uuid::v7()->toString();
    }

    private static function waitUntil(int $epochMs): void
    {
        while (EpochMillis::now() <= $epochMs) {
            usleep(10_000);
        }
    }

    /**
     * A store may keep the keys of a JSON object in another order: compare the contents only.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
