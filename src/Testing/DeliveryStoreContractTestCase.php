<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\ClaimedDelivery;
use Freyr\MessageBroker\Webhook\DeliveryCompletion;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contract tests of the {@see DeliveryStore} port. An adapter extends this
 * class, returns its store from createStore() and the dead letter store that
 * reads the dead letters of that store from deadLetterStore(), and inherits
 * the tests. The tests use the ports only: no SQL.
 *
 * Rules for the adapter:
 *  - createStore() returns a store that is EMPTY at the start of each test,
 *    and deadLetterStore() a dead letter store with no row. The test calls
 *    createStore() first.
 *  - The store reads the present time from its own source (for a database:
 *    the database time). The tests use the real time, with created_at values
 *    of the last minutes (an adapter that partitions by day prepares the
 *    partitions of today), and they show the lease rules with leases of 0 ms
 *    (due again at once) and 60 s (not due during the test), with no sleep.
 *    "Due" compares with millisecond precision: a store must use one
 *    conversion of its present time to milliseconds for the lease that it
 *    writes and for the due comparison (a database can round a stored value
 *    and not the compared one), or a lease of 0 ms is not due at once.
 *  - A lease value can differ from the time of the test by up to
 *    CLOCK_TOLERANCE_MS, for a store whose clock is not the clock of PHP.
 */
abstract class DeliveryStoreContractTestCase extends TestCase
{
    protected const int CLOCK_TOLERANCE_MS = 1_000;

    private const string LANE = 'contract';
    private const string OTHER_LANE = 'contract_other';
    private const string THIRD_LANE = 'contract_third';
    private const int LEASE_MS = 60_000;

    private ?DeliveryStore $store = null;

    /** A store that is empty at the start of the test. */
    abstract protected function createStore(): DeliveryStore;

    /** The dead letter store that reads the dead letters that the store of createStore() writes. */
    abstract protected function deadLetterStore(): DeadLetterStore;

    public function testAClaimedDeliveryCarriesEveryFieldOfTheInsertedRecord(): void
    {
        // Whole second, plus 123 ms: a store must keep the milliseconds.
        $createdAt = intdiv(EpochMillis::now(), 1000) * 1000 - 5_000 + 123;
        $inserted = new DeliveryRecord(
            id: self::newId(),
            messageId: self::newId(),
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
        );
        $this->store()->insert([$inserted]);

        $claimed = $this->store()->claim(self::each([self::LANE], 10), 10, self::LEASE_MS);

        self::assertCount(1, $claimed);
        $record = $claimed[0]->delivery;
        self::assertSame($inserted->id, $record->id);
        self::assertSame($inserted->messageId, $record->messageId);
        self::assertSame(self::LANE, $record->lane);
        self::assertSame('order.placed', $record->messageName);
        self::assertSame($inserted->body, $record->body, 'the body is bytes: no re-encoding');
        self::assertSame(self::sortKeys($inserted->headers), self::sortKeys($record->headers));
        self::assertSame($createdAt, $record->createdAt, 'the created_at keeps its milliseconds');
        self::assertSame(0, $record->attempts, 'a new row has no attempt');
        self::assertSame($claimed[0]->lease, $record->availableAt, 'the record has the new available_at');
    }

    public function testARecordWithoutHeadersComesBackWithAnEmptyHeaderList(): void
    {
        $this->store()->insert([$this->record()]);

        self::assertSame([], $this->claim()[0]->delivery->headers);
    }

    public function testAnInsertOfAnEmptyListWritesNothing(): void
    {
        $this->store()->insert([]);

        self::assertSame([], $this->claim());
    }

    public function testAnInsertOfSeveralRecordsWritesEachOfThem(): void
    {
        $records = [$this->record(), $this->record(), $this->record(lane: self::OTHER_LANE)];
        $this->store()->insert($records);

        self::assertEqualsCanonicalizing(self::ids($records), self::claimedIds($this->claim([self::LANE, self::OTHER_LANE])));
    }

    public function testAClaimTakesOnlyTheDueUnfinishedRowsOfTheAskedLanes(): void
    {
        $ours = $this->record();
        $other = $this->record(lane: self::OTHER_LANE);
        $notAsked = $this->record(lane: 'contract_not_asked');
        $notDue = $this->record(availableAt: EpochMillis::now() + 60_000);
        $this->store()->insert([$ours, $other, $notAsked, $notDue]);

        $claimed = $this->claim([self::LANE, self::OTHER_LANE]);

        self::assertEqualsCanonicalizing([$ours->id, $other->id], self::claimedIds($claimed));
    }

    public function testARowWithAnAvailableAtInThePastIsDue(): void
    {
        $row = $this->record(availableAt: EpochMillis::now() - 1_000);
        $this->store()->insert([$row]);

        self::assertSame([$row->id], self::claimedIds($this->claim()));
    }

    public function testAClaimKeepsTheLimitForEachLaneAndTheTotal(): void
    {
        $ours = [$this->record(), $this->record(), $this->record()];
        $other = [$this->record(lane: self::OTHER_LANE), $this->record(lane: self::OTHER_LANE), $this->record(lane: self::OTHER_LANE)];
        $this->store()->insert([...$ours, ...$other]);

        $first = $this->store()->claim(self::each([self::LANE, self::OTHER_LANE], 2), 10, self::LEASE_MS);
        $second = $this->store()->claim(self::each([self::LANE, self::OTHER_LANE], 5), 1, self::LEASE_MS);
        $third = $this->store()->claim(self::each([self::LANE, self::OTHER_LANE], 5), 10, self::LEASE_MS);

        self::assertSame([
            self::LANE => 2,
            self::OTHER_LANE => 2,
        ], self::countByLane($first), 'at most the limit of each lane');
        self::assertCount(1, $second, 'at most the total');
        self::assertCount(1, $third, 'the rest');
        self::assertEqualsCanonicalizing(
            [...self::ids($ours), ...self::ids($other)],
            self::claimedIds([...$first, ...$second, ...$third]),
        );
    }

    public function testTheTotalCutsTheClaimInTheOrderOfTheLaneList(): void
    {
        $ours = [$this->record(), $this->record()];
        $other = [$this->record(lane: self::OTHER_LANE), $this->record(lane: self::OTHER_LANE)];
        $this->store()->insert([...$ours, ...$other]);

        // A lease of 0 ms: each claim sees all four rows again.
        $oursFirst = $this->store()->claim(self::each([self::LANE, self::OTHER_LANE], 2), 2, 0);
        $otherFirst = $this->store()->claim(self::each([self::OTHER_LANE, self::LANE], 2), 2, 0);
        $split = $this->store()->claim(self::each([self::OTHER_LANE, self::LANE], 2), 3, 0);

        self::assertSame([
            self::LANE => 2,
        ], self::countByLane($oursFirst), 'the first lane of the list fills the total');
        self::assertSame([
            self::OTHER_LANE => 2,
        ], self::countByLane($otherFirst), 'the other order gives the other lane');
        self::assertSame([
            self::LANE => 1,
            self::OTHER_LANE => 2,
        ], self::countByLane($split), 'the later lane gets only the rest of the total');
    }

    public function testEachLaneOfAClaimHasItsOwnRowLimit(): void
    {
        $this->store()->insert([
            $this->record(), $this->record(), $this->record(),
            $this->record(lane: self::OTHER_LANE), $this->record(lane: self::OTHER_LANE), $this->record(lane: self::OTHER_LANE),
            $this->record(lane: self::THIRD_LANE), $this->record(lane: self::THIRD_LANE),
        ]);

        $claimed = $this->store()->claim([
            self::LANE => 1,
            self::OTHER_LANE => 3,
            self::THIRD_LANE => 2,
        ], 10, self::LEASE_MS);

        self::assertSame([
            self::LANE => 1,
            self::OTHER_LANE => 3,
            self::THIRD_LANE => 2,
        ], self::countByLane($claimed));
    }

    public function testTheTotalCutsAClaimWithADifferentLimitForEachLaneInTheOrderOfTheKeys(): void
    {
        $this->store()->insert([
            $this->record(), $this->record(), $this->record(),
            $this->record(lane: self::OTHER_LANE), $this->record(lane: self::OTHER_LANE), $this->record(lane: self::OTHER_LANE),
        ]);

        // A lease of 0 ms: each claim sees all six rows again.
        $otherFirst = $this->store()->claim([
            self::OTHER_LANE => 1,
            self::LANE => 3,
        ], 3, 0);
        $oursFirst = $this->store()->claim([
            self::LANE => 2,
            self::OTHER_LANE => 3,
        ], 4, 0);

        self::assertSame([
            self::LANE => 2,
            self::OTHER_LANE => 1,
        ], self::countByLane($otherFirst), 'the first key takes its own limit, the second the rest of the total');
        self::assertSame([
            self::LANE => 2,
            self::OTHER_LANE => 2,
        ], self::countByLane($oursFirst));
    }

    public function testTheDueCountsCountTheDueUnfinishedRowsOfEachLaneUpToTheCapAndChangeNoRow(): void
    {
        $due = [$this->record(), $this->record(availableAt: EpochMillis::now() - 1_000), $this->record()];
        $notDue = $this->record(availableAt: EpochMillis::now() + 60_000);
        $finished = $this->record();
        $other = $this->record(lane: self::OTHER_LANE);
        $this->store()->insert([...$due, $notDue, $finished, $other, $this->record(lane: 'contract_not_asked')]);
        // A lease of 0 ms: the other rows of the claim are due again at once.
        $leased = $this->store()->claim(self::each([self::LANE], 10), 10, 0);
        $this->store()->complete([DeliveryCompletion::delivered(self::byId($leased, [$finished->id])[0])]);

        $capped = $this->store()->dueCounts([self::THIRD_LANE, self::LANE, self::OTHER_LANE], 2);
        $all = $this->store()->dueCounts([self::LANE, self::OTHER_LANE], 3);
        $above = $this->store()->dueCounts([self::LANE], 4);

        self::assertSame([
            self::THIRD_LANE => 0,
            self::LANE => 2,
            self::OTHER_LANE => 1,
        ], $capped, 'each given lane in the order of the list, also with 0; at most the cap');
        self::assertSame([
            self::LANE => 3,
            self::OTHER_LANE => 1,
        ], $all, 'a cap equal to the count');
        self::assertSame([
            self::LANE => 3,
        ], $above, 'a row that is not due and a finished row do not count');
        self::assertEqualsCanonicalizing(
            [...self::ids($due), $other->id],
            self::claimedIds($this->claim([self::LANE, self::OTHER_LANE])),
            'the count changed no row and took no lease',
        );
    }

    public function testALeasedRowIsNotDueForTheDueCounts(): void
    {
        $this->store()->insert([$this->record(), $this->record()]);
        $this->store()->claim(self::each([self::LANE], 1), 1, self::LEASE_MS);

        self::assertSame([
            self::LANE => 1,
        ], $this->store()->dueCounts([self::LANE], 10));
    }

    public function testTheDueCountsOfAnEmptyLaneListAreEmpty(): void
    {
        $this->store()->insert([$this->record()]);

        self::assertSame([], $this->store()->dueCounts([], 10));
    }

    public function testANumericLaneIdIsReadAsAStringInTheClaimAndInTheDueCounts(): void
    {
        $rows = [$this->record(lane: '42'), $this->record(lane: '42'), $this->record(lane: '42')];
        $this->store()->insert($rows);

        $counts = $this->store()->dueCounts(['42'], 10);
        // PHP makes the key '42' the integer 42; the store must find the lane '42'.
        $claimed = $this->store()->claim([
            '42' => 2,
        ], 10, self::LEASE_MS);

        self::assertSame(['42'], array_map(strval(...), array_keys($counts)));
        self::assertSame(3, $counts['42']);
        self::assertCount(2, $claimed, 'the row limit of the lane 42');
        self::assertSame(['42', '42'], array_map(static fn (ClaimedDelivery $c): string => $c->delivery->lane, $claimed));
    }

    public function testAnEmptyLaneListClaimsNothing(): void
    {
        $this->store()->insert([$this->record()]);

        self::assertSame([], $this->store()->claim(self::each([], 10), 10, self::LEASE_MS));
        self::assertCount(1, $this->claim(), 'the row is still due');
    }

    public function testAClaimSetsTheLeaseAndALeasedRowIsNotClaimedAgain(): void
    {
        $this->store()->insert([$this->record()]);

        $before = EpochMillis::now();
        $claimed = $this->claim();
        $after = EpochMillis::now();

        self::assertCount(1, $claimed);
        self::assertGreaterThanOrEqual($before + self::LEASE_MS - self::CLOCK_TOLERANCE_MS, $claimed[0]->lease);
        self::assertLessThanOrEqual($after + self::LEASE_MS + self::CLOCK_TOLERANCE_MS, $claimed[0]->lease);
        self::assertSame([], $this->claim(), 'the lease holds the row');
    }

    public function testARowWhoseLeaseEndedIsDueAgainWithANewLeaseAndNoAttempt(): void
    {
        $row = $this->record();
        $this->store()->insert([$row]);

        $first = $this->store()->claim(self::each([self::LANE], 10), 10, 0);
        $second = $this->claim();

        self::assertSame([$row->id], self::claimedIds($second), 'a lease of 0 ms ends at once');
        self::assertGreaterThan($first[0]->lease, $second[0]->lease);
        self::assertSame(0, $second[0]->delivery->attempts, 'a claim uses no attempt');
    }

    public function testADeliveredOutcomeFinishesTheRow(): void
    {
        $row = $this->record();
        $this->store()->insert([$row]);
        $claimed = $this->store()->claim(self::each([self::LANE], 10), 10, 0);

        $changed = $this->store()->complete([DeliveryCompletion::delivered($claimed[0])]);

        self::assertSame([$row->id], $changed);
        self::assertSame([], $this->claim(), 'a finished row is never claimed again');
    }

    public function testARetryOutcomeAddsTheAttemptAndSetsTheRetryTime(): void
    {
        $soon = $this->record();
        $later = $this->record();
        $this->store()->insert([$soon, $later]);
        [$a, $b] = self::byId($this->claim(), [$soon->id, $later->id]);
        $retryAt = EpochMillis::now() - 1_000;

        $changed = $this->store()->complete([
            DeliveryCompletion::retry($a, $retryAt),
            DeliveryCompletion::retry($b, EpochMillis::now() + 60_000),
        ]);

        self::assertEqualsCanonicalizing([$soon->id, $later->id], $changed);
        $again = $this->store()->claim(self::each([self::LANE], 10), 10, 0);
        self::assertSame([$soon->id], self::claimedIds($again), 'only the row whose retry time passed is due');
        self::assertSame(1, $again[0]->delivery->attempts);
        self::assertSame($soon->body, $again[0]->delivery->body);
    }

    public function testADeadOutcomeWritesTheDeadLetterAndFinishesTheRow(): void
    {
        $row = $this->record(headers: [
            'correlation_id' => 'c-9',
        ]);
        $this->store()->insert([$row]);
        $claimed = $this->claim()[0];
        $deadLetter = $this->deadLetter($claimed, attempts: 8);

        $changed = $this->store()->complete([DeliveryCompletion::dead($claimed, $deadLetter)]);

        self::assertSame([$row->id], $changed);
        self::assertSame([], $this->store()->claim(self::each([self::LANE], 10), 10, 0), 'a dead row is never claimed again');
        $stored = $this->deadLetterStore()->find($deadLetter->id);
        self::assertNotNull($stored);
        self::assertSame('webhook:'.self::LANE, $stored->source);
        self::assertSame($row->messageId, $stored->messageId);
        self::assertSame($row->messageName, $stored->messageName);
        self::assertSame($row->body, $stored->body);
        self::assertSame($row->headers, $stored->headers);
        self::assertSame('failed', $stored->errorClass);
        self::assertSame('500 boom', $stored->errorMessage);
        self::assertSame(8, $stored->attempts);
        self::assertSame($deadLetter->failedAt, $stored->failedAt);
        self::assertTrue($stored->replayable);
        self::assertNull($stored->replayedAt);
    }

    public function testACompleteWithAnOldLeaseChangesNoRowAndWritesNoDeadLetter(): void
    {
        $row = $this->record();
        $this->store()->insert([$row]);
        $old = $this->store()->claim(self::each([self::LANE], 10), 10, 0)[0];
        $current = $this->claim()[0];

        $late = $this->store()->complete([
            DeliveryCompletion::dead($old, $this->deadLetter($old)),
            DeliveryCompletion::delivered($old),
            DeliveryCompletion::retry($old, EpochMillis::now() - 1_000),
        ]);

        self::assertSame([], $late, 'the lease of the old claim ended');
        self::assertSame(0, $this->deadLetterStore()->count(new DeadLetterFilter()));
        self::assertSame([], $this->claim(), 'the row keeps the lease of the current claim');
        self::assertSame([$row->id], $this->store()->complete([DeliveryCompletion::delivered($current)]));
    }

    public function testAFinishedRowIsNotChangedAgain(): void
    {
        $row = $this->record();
        $this->store()->insert([$row]);
        $claimed = $this->claim()[0];
        $this->store()->complete([DeliveryCompletion::delivered($claimed)]);

        $again = $this->store()->complete([
            DeliveryCompletion::retry($claimed, EpochMillis::now() - 1_000),
            DeliveryCompletion::dead($claimed, $this->deadLetter($claimed)),
        ]);

        self::assertSame([], $again);
        self::assertSame([], $this->store()->claim(self::each([self::LANE], 10), 10, 0));
        self::assertSame(0, $this->deadLetterStore()->count(new DeadLetterFilter()));
    }

    public function testACompleteOfAnEmptyListChangesNothing(): void
    {
        self::assertSame([], $this->store()->complete([]));
    }

    public function testOneMessageThatDiesInTwoLanesHasTwoOpenDeadLetters(): void
    {
        $messageId = self::newId();
        $this->store()->insert([
            $this->record(messageId: $messageId),
            $this->record(lane: self::OTHER_LANE, messageId: $messageId),
        ]);
        $claimed = $this->claim([self::LANE, self::OTHER_LANE]);

        $this->store()->complete(array_map(
            fn (ClaimedDelivery $c): DeliveryCompletion => DeliveryCompletion::dead($c, $this->deadLetter($c)),
            $claimed,
        ));

        $rows = $this->deadLetterStore()->list(new DeadLetterFilter(messageId: $messageId));
        self::assertEqualsCanonicalizing(
            ['webhook:'.self::LANE, 'webhook:'.self::OTHER_LANE],
            array_map(static fn (DeadLetter $d): string => $d->source, $rows),
        );
    }

    public function testTwoRowsOfOneMessageThatDieInOneLaneHaveOneOpenDeadLetter(): void
    {
        $messageId = self::newId();
        $this->store()->insert([$this->record(messageId: $messageId), $this->record(messageId: $messageId)]);
        $claimed = $this->claim();
        $first = $this->deadLetter($claimed[0], attempts: 3);
        $second = $this->deadLetter($claimed[1], attempts: 5);

        $this->store()->complete([DeliveryCompletion::dead($claimed[0], $first)]);
        $this->store()->complete([DeliveryCompletion::dead($claimed[1], $second)]);

        $rows = $this->deadLetterStore()->list(new DeadLetterFilter(messageId: $messageId));
        self::assertCount(1, $rows, 'the open row rule: one open row for each message ID and source');
        self::assertSame($first->id, $rows[0]->id, 'the open row keeps its first id');
        self::assertSame(5, $rows[0]->attempts, 'and gets the data of the second dead letter');
    }

    public function testAnAbandonMovesOnlyTheUnfinishedRowsBeforeTheDateOfTheGivenLanesAndReturnsTheirNumber(): void
    {
        $now = EpochMillis::now();
        $before = $now - 30_000;
        $old = $this->record(createdAt: $now - 50_000);
        $oldOtherLane = $this->record(lane: self::OTHER_LANE, createdAt: $now - 40_000);
        $oldNotGiven = $this->record(lane: self::THIRD_LANE, createdAt: $now - 50_000);
        $atTheDate = $this->record(createdAt: $before);
        $justBefore = $this->record(createdAt: $before - 1);
        $young = $this->record(createdAt: $now - 5_000);
        $oldFinished = $this->record(createdAt: $now - 50_000);
        $this->store()->insert([$old, $oldOtherLane, $oldNotGiven, $atTheDate, $justBefore, $young, $oldFinished]);
        $finished = self::byId($this->store()->claim(self::each([self::LANE], 10), 10, 0), [$oldFinished->id]);
        $this->store()->complete([DeliveryCompletion::delivered($finished[0])]);

        $count = $this->store()->abandon($before, [self::LANE, self::OTHER_LANE], 10);

        self::assertSame(3, $count, 'created_at at the date is not earlier than it');
        self::assertEqualsCanonicalizing(
            [$oldNotGiven->id, $atTheDate->id, $young->id],
            self::claimedIds($this->store()->claim(self::each([self::LANE, self::OTHER_LANE, self::THIRD_LANE], 10), 10, self::LEASE_MS)),
            'the other rows stay unfinished and due',
        );
        self::assertEqualsCanonicalizing(
            [$old->messageId, $oldOtherLane->messageId, $justBefore->messageId],
            array_map(static fn (DeadLetter $d): string => $d->messageId, $this->deadLetterStore()->list(new DeadLetterFilter())),
            'one dead letter for each abandoned row, none for the finished row',
        );
    }

    public function testAnAbandonWithNoLaneListTakesTheRowsOfAllLanes(): void
    {
        $now = EpochMillis::now();
        $rows = [
            $this->record(createdAt: $now - 50_000),
            $this->record(lane: self::OTHER_LANE, createdAt: $now - 50_000),
            $this->record(lane: self::THIRD_LANE, createdAt: $now - 50_000),
        ];
        $young = $this->record(createdAt: $now - 5_000);
        $this->store()->insert([...$rows, $young]);

        self::assertSame(3, $this->store()->abandon($now - 30_000, null, 10));

        self::assertSame([$young->id], self::claimedIds($this->store()->claim(self::each([self::LANE, self::OTHER_LANE, self::THIRD_LANE], 10), 10, self::LEASE_MS)));
    }

    public function testAnAbandonWithAnEmptyLaneListTakesNothing(): void
    {
        $row = $this->record(createdAt: EpochMillis::now() - 50_000);
        $this->store()->insert([$row]);

        self::assertSame(0, $this->store()->abandon(EpochMillis::now(), [], 10));

        self::assertSame([$row->id], self::claimedIds($this->claim()));
    }

    public function testAnAbandonKeepsTheLimitAndAFinishedRowIsNotAbandonedAgain(): void
    {
        $now = EpochMillis::now();
        $this->store()->insert([
            $this->record(createdAt: $now - 50_000),
            $this->record(createdAt: $now - 45_000),
            $this->record(lane: self::OTHER_LANE, createdAt: $now - 40_000),
        ]);

        self::assertSame(2, $this->store()->abandon($now, null, 2));
        self::assertSame(1, $this->store()->abandon($now, null, 2));
        self::assertSame(0, $this->store()->abandon($now, null, 2));

        self::assertSame(3, $this->deadLetterStore()->count(new DeadLetterFilter()));
        self::assertSame([], $this->claim([self::LANE, self::OTHER_LANE]));
    }

    public function testTheDeadLetterOfAnAbandonedRowHasTheErrorClassAbandonedAndTheSourceOfItsLane(): void
    {
        $now = EpochMillis::now();
        $row = new DeliveryRecord(
            id: self::newId(),
            messageId: self::newId(),
            lane: self::OTHER_LANE,
            messageName: 'order.placed',
            body: "\x00raw{\"order_id\":\"o-1\"}",
            headers: [
                'correlation_id' => 'c-7',
            ],
            createdAt: $now - 50_000,
            attempts: 3,
        );
        $this->store()->insert([$row]);
        $before = intdiv($now, 1000) * 1000 - 10_000 + 456;

        $start = EpochMillis::now();
        $this->store()->abandon($before, [self::OTHER_LANE], 10);
        $end = EpochMillis::now();

        $all = $this->deadLetterStore()->list(new DeadLetterFilter());
        self::assertCount(1, $all);
        $deadLetter = $all[0];
        self::assertSame('webhook:'.self::OTHER_LANE, $deadLetter->source);
        self::assertSame($row->messageId, $deadLetter->messageId);
        self::assertSame('order.placed', $deadLetter->messageName);
        self::assertSame($row->body, $deadLetter->body);
        self::assertSame($row->headers, $deadLetter->headers);
        self::assertSame('abandoned', $deadLetter->errorClass);
        self::assertSame(
            'Abandoned by an operator: created before '.EpochMillis::toDateTime($before)->format('Y-m-d H:i:s.v').' UTC',
            $deadLetter->errorMessage,
        );
        self::assertSame('', $deadLetter->errorTrace);
        self::assertSame(3, $deadLetter->attempts, 'the attempts of the row: an abandon sends no request');
        self::assertGreaterThanOrEqual($start - self::CLOCK_TOLERANCE_MS, $deadLetter->failedAt);
        self::assertLessThanOrEqual($end + self::CLOCK_TOLERANCE_MS, $deadLetter->failedAt);
        self::assertTrue($deadLetter->replayable);
        self::assertNull($deadLetter->replayedAt);
    }

    public function testTwoAbandonedRowsOfOneMessageInOneLaneHaveOneOpenDeadLetter(): void
    {
        $now = EpochMillis::now();
        $messageId = self::newId();
        $this->store()->insert([
            $this->record(messageId: $messageId, createdAt: $now - 50_000),
            $this->record(messageId: $messageId, createdAt: $now - 40_000),
            $this->record(lane: self::OTHER_LANE, messageId: $messageId, createdAt: $now - 40_000),
        ]);

        self::assertSame(3, $this->store()->abandon($now, null, 10));

        self::assertEqualsCanonicalizing(
            ['webhook:'.self::LANE, 'webhook:'.self::OTHER_LANE],
            array_map(static fn (DeadLetter $d): string => $d->source, $this->deadLetterStore()->list(new DeadLetterFilter(messageId: $messageId))),
            'the open row rule: one open row for each message ID and source',
        );
    }

    public function testALeasedRowIsAbandonedAndTheLateResultOfItsWorkerChangesNothing(): void
    {
        $row = $this->record(createdAt: EpochMillis::now() - 50_000);
        $this->store()->insert([$row]);
        $leased = $this->claim()[0];

        self::assertSame(1, $this->store()->abandon(EpochMillis::now(), [self::LANE], 10), 'a lease does not protect a row from the abandon');

        self::assertSame([], $this->store()->complete([DeliveryCompletion::delivered($leased)]), 'the lease check: the row is finished');
        self::assertSame([], $this->store()->complete([
            DeliveryCompletion::retry($leased, EpochMillis::now() - 1_000),
            DeliveryCompletion::dead($leased, $this->deadLetter($leased)),
        ]));
        self::assertSame([], $this->store()->claim(self::each([self::LANE], 10), 10, 0), 'the row stays finished');
        $all = $this->deadLetterStore()->list(new DeadLetterFilter());
        self::assertCount(1, $all);
        self::assertSame('abandoned', $all[0]->errorClass, 'only the dead letter of the abandon');
    }

    public function testTheCountOfUnfinishedRowsGivesTheRowsBeforeTheDateOfEachGivenLaneAndChangesNothing(): void
    {
        $now = EpochMillis::now();
        $before = $now - 30_000;
        $leased = $this->record(createdAt: $now - 50_000);
        $this->store()->insert([
            $leased,
            $this->record(createdAt: $before - 1),
            $this->record(lane: self::OTHER_LANE, createdAt: $now - 40_000),
            $this->record(lane: self::THIRD_LANE, createdAt: $now - 50_000),
            $this->record(createdAt: $before),
            $this->record(lane: self::OTHER_LANE, createdAt: $now - 5_000),
        ]);
        $finished = $this->record(lane: self::OTHER_LANE, createdAt: $now - 50_000);
        $this->store()->insert([$finished]);
        $this->store()->complete([DeliveryCompletion::delivered(self::byId($this->claim([self::OTHER_LANE]), [$finished->id])[0])]);
        $this->store()->claim(self::each([self::LANE], 1), 1, self::LEASE_MS);

        $counts = $this->store()->countUnfinished($before, [self::OTHER_LANE, self::LANE]);

        self::assertSame([
            self::LANE => 2,
            self::OTHER_LANE => 1,
        ], $counts, 'in the order of the lane names; a leased row counts; a finished row and a row at the date do not');
        self::assertSame(3, $this->store()->abandon($before, [self::LANE, self::OTHER_LANE], 10), 'the count is what an abandon then moves');
    }

    public function testTheCountOfUnfinishedRowsWithNoLaneListGivesEachLaneAndWithAnEmptyListNone(): void
    {
        $now = EpochMillis::now();
        $this->store()->insert([
            $this->record(lane: self::THIRD_LANE, createdAt: $now - 50_000),
            $this->record(createdAt: $now - 50_000),
            $this->record(lane: self::OTHER_LANE, createdAt: $now - 50_000),
            $this->record(lane: self::OTHER_LANE, createdAt: $now - 50_000),
        ]);

        self::assertSame([
            self::LANE => 1,
            self::OTHER_LANE => 2,
            self::THIRD_LANE => 1,
        ], $this->store()->countUnfinished($now, null));
        self::assertSame([], $this->store()->countUnfinished($now, []));
        self::assertSame([], $this->store()->countUnfinished($now - 60_000, null), 'no row before the date');
    }

    protected function store(): DeliveryStore
    {
        return $this->store ??= $this->createStore();
    }

    /**
     * @param list<string>|null $lanes
     *
     * @return list<ClaimedDelivery>
     */
    private function claim(?array $lanes = null): array
    {
        return $this->store()->claim(self::each($lanes ?? [self::LANE], 10), 10, self::LEASE_MS);
    }

    /**
     * @param list<string> $lanes
     *
     * @return array<string, int> the same row limit for each lane, in the order of the list
     */
    private static function each(array $lanes, int $limit): array
    {
        return array_fill_keys($lanes, $limit);
    }

    /** @param array<string, mixed> $headers */
    private function record(
        string $lane = self::LANE,
        ?string $messageId = null,
        ?int $createdAt = null,
        ?int $availableAt = null,
        array $headers = [],
    ): DeliveryRecord {
        return new DeliveryRecord(
            id: self::newId(),
            messageId: $messageId ?? self::newId(),
            lane: $lane,
            messageName: 'order.placed',
            body: '{"order_id":"o-1"}',
            headers: $headers,
            createdAt: $createdAt ?? EpochMillis::now() - 5_000,
            availableAt: $availableAt,
        );
    }

    private function deadLetter(ClaimedDelivery $claimed, int $attempts = 1): DeadLetter
    {
        $record = $claimed->delivery;

        return new DeadLetter(
            id: self::newId(),
            source: 'webhook:'.$record->lane,
            messageId: $record->messageId,
            messageName: $record->messageName,
            body: $record->body,
            headers: $record->headers,
            errorClass: 'failed',
            errorMessage: '500 boom',
            errorTrace: '',
            attempts: $attempts,
            failedAt: intdiv(EpochMillis::now(), 1000) * 1000 + 321,
        );
    }

    private static function newId(): string
    {
        return Uuid::v7()->toString();
    }

    /**
     * @param list<DeliveryRecord> $records
     *
     * @return list<string>
     */
    private static function ids(array $records): array
    {
        return array_map(static fn (DeliveryRecord $r): string => $r->id, $records);
    }

    /**
     * @param list<ClaimedDelivery> $claimed
     *
     * @return list<string>
     */
    private static function claimedIds(array $claimed): array
    {
        return array_map(static fn (ClaimedDelivery $c): string => $c->delivery->id, $claimed);
    }

    /**
     * @param list<ClaimedDelivery> $claimed
     *
     * @return array<string, int>
     */
    private static function countByLane(array $claimed): array
    {
        $counts = [];
        foreach ($claimed as $c) {
            $counts[$c->delivery->lane] = ($counts[$c->delivery->lane] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param list<ClaimedDelivery> $claimed
     * @param list<string> $ids
     *
     * @return list<ClaimedDelivery> the claimed deliveries of the ids, in the order of the ids
     */
    private static function byId(array $claimed, array $ids): array
    {
        $byId = [];
        foreach ($claimed as $c) {
            $byId[$c->delivery->id] = $c;
        }

        return array_map(static function (string $id) use ($byId): ClaimedDelivery {
            self::assertArrayHasKey($id, $byId, "the claim holds {$id}");

            return $byId[$id];
        }, $ids);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
