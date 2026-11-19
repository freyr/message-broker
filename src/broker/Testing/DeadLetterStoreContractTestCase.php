<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStat;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * Contract tests of the {@see DeadLetterStore} port. An adapter extends this
 * class, returns its store from createStore(), and inherits the tests.
 *
 * Rules for the adapter:
 *  - createStore() returns a store that is EMPTY at the start of each test.
 *  - The ids of the dead letters are UUID strings (as DeadLetter::fromFailure()
 *    makes them); the failed_at values are of the last hours.
 */
abstract class DeadLetterStoreContractTestCase extends TestCase
{
    private ?DeadLetterStore $store = null;

    /** A store that is empty at the start of the test. */
    abstract protected function createStore(): DeadLetterStore;

    public function testStoreAndFindRoundTripEveryField(): void
    {
        $failedAt = intdiv(EpochMillis::now(), 1000) * 1000 - 60_000 + 123;
        $deadLetter = new DeadLetter(
            id: self::newId(),
            source: 'orders_q',
            messageId: 'msg-1',
            messageName: 'order.placed',
            body: "\x00\x01binary\xff{\"zażółć\":1}",
            headers: [
                'x-attempt' => 3,
                'x-flag' => true,
                'x-nested' => [
                    'list' => ['a', 'b'],
                    'map' => [
                        'k' => 'v',
                    ],
                ],
            ],
            errorClass: 'RuntimeException',
            errorMessage: "handler failed <- root cause\nzażółć",
            errorTrace: "#0 file.php(1): fail()\n#1 {main}",
            attempts: 7,
            failedAt: $failedAt,
        );
        $this->store()->store($deadLetter);

        $found = $this->store()->find($deadLetter->id);

        self::assertNotNull($found);
        self::assertSame($deadLetter->id, $found->id);
        self::assertSame('orders_q', $found->source);
        self::assertSame('msg-1', $found->messageId);
        self::assertSame('order.placed', $found->messageName);
        self::assertSame($deadLetter->body, $found->body, 'the body is bytes: no re-encoding');
        self::assertSame(self::sortKeys($deadLetter->headers), self::sortKeys($found->headers));
        self::assertSame('RuntimeException', $found->errorClass);
        self::assertSame($deadLetter->errorMessage, $found->errorMessage);
        self::assertSame($deadLetter->errorTrace, $found->errorTrace);
        self::assertSame(7, $found->attempts);
        self::assertSame($failedAt, $found->failedAt, 'failed_at keeps its milliseconds');
        self::assertNull($found->replayedAt, 'a new dead letter is not replayed');
        self::assertTrue($found->replayable, 'replayable by default');
    }

    public function testStoresTextThatEachSinkCanStore(): void
    {
        $fffd = "\u{FFFD}";
        $body = "raw\x00bytes\xff";
        $deadLetter = new DeadLetter(
            id: self::newId(),
            source: 'orders_q',
            messageId: 'msg-1',
            messageName: str_repeat('ż', 300),
            body: $body,
            headers: [
                'x-invalid' => "a\xffb",
                'x-nul' => "a\x00b",
                "x-key\x00" => 'v',
                'x-nested' => [
                    'deep' => "c\xfed",
                ],
            ],
            errorClass: 'RuntimeException',
            errorMessage: "bad \xff text \x00 here",
            errorTrace: '#0 trace-line',
            attempts: 1,
            failedAt: self::at(-10),
        );

        $this->store()->store($deadLetter);
        $found = $this->store()->find($deadLetter->id);

        self::assertNotNull($found);
        self::assertSame(str_repeat('ż', 255), $found->messageName);
        self::assertSame(self::sortKeys([
            'x-invalid' => "a{$fffd}b",
            'x-nul' => "a{$fffd}b",
            "x-key{$fffd}" => 'v',
            'x-nested' => [
                'deep' => "c{$fffd}d",
            ],
        ]), self::sortKeys($found->headers));
        self::assertSame("bad {$fffd} text {$fffd} here", $found->errorMessage);
        self::assertSame($body, $found->body, 'the body stays the exact bytes');
    }

    public function testTheReplayableMarkRoundTripsAsFalseAndAsTrue(): void
    {
        $raw = $this->deadLetter(messageId: 'm-raw', replayable: false);
        $canonical = $this->deadLetter(messageId: 'm-canonical', replayable: true);
        $this->store()->store($raw);
        $this->store()->store($canonical);

        self::assertFalse($this->store()->find($raw->id)?->replayable);
        self::assertTrue($this->store()->find($canonical->id)?->replayable);
    }

    public function testMarkReplayedOfARowThatIsNotReplayableKeepsTheMark(): void
    {
        $raw = $this->deadLetter(replayable: false);
        $this->store()->store($raw);

        $this->store()->markReplayed($raw->id);

        $found = $this->store()->find($raw->id);
        self::assertNotNull($found?->replayedAt);
        self::assertFalse($found->replayable);
    }

    public function testASecondStoreOfAnOpenPairUpdatesTheRowAndKeepsItsId(): void
    {
        $first = $this->deadLetter(messageId: 'm-1', source: 'orders_q', failedAt: self::at(-300));
        $failedAt = intdiv(EpochMillis::now(), 1000) * 1000 - 60_000 + 456;
        $second = new DeadLetter(
            id: self::newId(),
            source: 'orders_q',
            messageId: 'm-1',
            messageName: 'order.renamed',
            body: "raw\xffbytes",
            headers: [
                'x-second' => 'yes',
            ],
            errorClass: 'LogicException',
            errorMessage: 'second failure',
            errorTrace: '#0 second-trace',
            attempts: 9,
            failedAt: $failedAt,
            replayable: false,
        );
        $this->store()->store($first);

        $this->store()->store($second);

        self::assertSame(1, $this->store()->count(new DeadLetterFilter()), 'one open row for the pair');
        self::assertNull($this->store()->find($second->id), 'the id of the second dead letter is discarded');
        $found = $this->store()->find($first->id);
        self::assertNotNull($found);
        self::assertSame('orders_q', $found->source);
        self::assertSame('m-1', $found->messageId);
        self::assertSame('order.renamed', $found->messageName);
        self::assertSame("raw\xffbytes", $found->body);
        self::assertSame([
            'x-second' => 'yes',
        ], $found->headers);
        self::assertSame('LogicException', $found->errorClass);
        self::assertSame('second failure', $found->errorMessage);
        self::assertSame('#0 second-trace', $found->errorTrace);
        self::assertSame(9, $found->attempts);
        self::assertSame($failedAt, $found->failedAt);
        self::assertNull($found->replayedAt, 'the row stays open');
        self::assertFalse($found->replayable);
    }

    public function testAStoreAfterAReplayOfThePairAddsANewOpenRow(): void
    {
        $replayed = $this->deadLetter(messageId: 'm-1', failedAt: self::at(-300));
        $this->store()->store($replayed);
        $this->store()->markReplayed($replayed->id);
        $again = $this->deadLetter(messageId: 'm-1', failedAt: self::at(-100));

        $this->store()->store($again);

        self::assertSame(2, $this->store()->count(new DeadLetterFilter()));
        $kept = $this->store()->find($replayed->id);
        self::assertNotNull($kept?->replayedAt, 'the replayed row stays as it was');
        self::assertSame($replayed->failedAt, $kept->failedAt);
        $open = $this->store()->find($again->id);
        self::assertNotNull($open, 'a new row with the id of the new dead letter');
        self::assertNull($open->replayedAt);
    }

    public function testAStoreOfAnIdThatIsAnOpenRowChangesNothing(): void
    {
        $first = $this->deadLetter(messageName: 'order.placed', messageId: 'm-1', failedAt: self::at(-300));
        $this->store()->store($first);
        $samePair = new DeadLetter(
            id: $first->id,
            source: $first->source,
            messageId: 'm-1',
            messageName: 'order.changed',
            body: '{"other":"bytes"}',
            headers: [],
            errorClass: 'LogicException',
            errorMessage: 'other failure',
            errorTrace: '#0 other',
            attempts: 8,
            failedAt: self::at(-100),
            replayable: false,
        );
        $otherPair = new DeadLetter(
            id: $first->id,
            source: 'billing_q',
            messageId: 'm-other',
            messageName: 'order.other',
            body: '{"other":"bytes"}',
            headers: [],
            errorClass: 'LogicException',
            errorMessage: 'other failure',
            errorTrace: '#0 other',
            attempts: 8,
            failedAt: self::at(-100),
        );

        $this->store()->store($samePair);
        $this->store()->store($otherPair);

        self::assertSame(1, $this->store()->count(new DeadLetterFilter()));
        $found = $this->store()->find($first->id);
        self::assertNotNull($found);
        self::assertSame('orders_q', $found->source);
        self::assertSame('m-1', $found->messageId);
        self::assertSame('order.placed', $found->messageName);
        self::assertSame('boom', $found->errorMessage);
        self::assertSame(3, $found->attempts);
        self::assertSame($first->failedAt, $found->failedAt);
        self::assertTrue($found->replayable);
    }

    public function testAStoreOfAnIdThatIsAReplayedRowChangesNothing(): void
    {
        $deadLetter = $this->deadLetter(messageId: 'm-1');
        $this->store()->store($deadLetter);
        $this->store()->markReplayed($deadLetter->id);
        $replayedAt = $this->store()->find($deadLetter->id)?->replayedAt;

        $this->store()->store($deadLetter);

        self::assertSame(1, $this->store()->count(new DeadLetterFilter()), 'no second row');
        self::assertNotNull($replayedAt);
        self::assertSame($replayedAt, $this->store()->find($deadLetter->id)?->replayedAt);
    }

    public function testTheSameMessageIdOnTwoSourcesGivesTwoRows(): void
    {
        $orders = $this->deadLetter(source: 'orders_q', messageId: 'm-1');
        $billing = $this->deadLetter(source: 'billing_q', messageId: 'm-1');

        $this->store()->store($orders);
        $this->store()->store($billing);

        self::assertSame(2, $this->store()->count(new DeadLetterFilter()));
        self::assertSame('orders_q', $this->store()->find($orders->id)?->source);
        self::assertSame('billing_q', $this->store()->find($billing->id)?->source);
    }

    public function testTwoMessageIdsOnOneSourceGiveTwoRows(): void
    {
        $first = $this->deadLetter(source: 'orders_q', messageId: 'm-1');
        $second = $this->deadLetter(source: 'orders_q', messageId: 'm-2');

        $this->store()->store($first);
        $this->store()->store($second);

        self::assertSame(2, $this->store()->count(new DeadLetterFilter()));
        self::assertSame('m-1', $this->store()->find($first->id)?->messageId);
        self::assertSame('m-2', $this->store()->find($second->id)?->messageId);
    }

    public function testAnUpsertChangesTheReplayableMarkInEachDirection(): void
    {
        $canonical = $this->deadLetter(messageId: 'm-1', replayable: true);
        $this->store()->store($canonical);

        $this->store()->store($this->deadLetter(messageId: 'm-1', replayable: false));
        self::assertFalse($this->store()->find($canonical->id)?->replayable, 'true to false');

        $this->store()->store($this->deadLetter(messageId: 'm-1', replayable: true));
        self::assertTrue($this->store()->find($canonical->id)?->replayable, 'false to true');
        self::assertSame(1, $this->store()->count(new DeadLetterFilter()));
    }

    public function testFindOfAnUnknownIdReturnsNull(): void
    {
        $this->store()->store($this->deadLetter());

        self::assertNull($this->store()->find(self::newId()));
    }

    public function testListReturnsTheNewestFirst(): void
    {
        $oldest = $this->deadLetter(failedAt: self::at(-300));
        $newest = $this->deadLetter(failedAt: self::at(-100));
        $middle = $this->deadLetter(failedAt: self::at(-200));
        foreach ([$oldest, $newest, $middle] as $deadLetter) {
            $this->store()->store($deadLetter);
        }

        self::assertSame(
            [$newest->id, $middle->id, $oldest->id],
            self::ids($this->store()->list(new DeadLetterFilter())),
        );
    }

    public function testListFiltersByMessageNameAndBySource(): void
    {
        $placed = $this->deadLetter(messageName: 'order.placed', source: 'orders_q', failedAt: self::at(-500));
        $cancelled = $this->deadLetter(messageName: 'order.cancelled', source: 'orders_q', failedAt: self::at(-400));
        $billing = $this->deadLetter(messageName: 'order.placed', source: 'billing_q', failedAt: self::at(-300));
        foreach ([$placed, $cancelled, $billing] as $deadLetter) {
            $this->store()->store($deadLetter);
        }

        self::assertSame([$billing->id, $placed->id], self::ids($this->store()->list(new DeadLetterFilter(messageName: 'order.placed'))));
        self::assertSame([$cancelled->id, $placed->id], self::ids($this->store()->list(new DeadLetterFilter(source: 'orders_q'))));
        self::assertSame(
            [$placed->id],
            self::ids($this->store()->list(new DeadLetterFilter(messageName: 'order.placed', source: 'orders_q'))),
            'the filters combine with AND',
        );
        self::assertSame([], $this->store()->list(new DeadLetterFilter(messageName: 'unknown.event')));
        self::assertCount(3, $this->store()->list(new DeadLetterFilter()), 'no filter sees every row');
    }

    public function testListFiltersByALowerFailedAtBound(): void
    {
        $old = $this->deadLetter(failedAt: self::at(-3_000));
        $recent = $this->deadLetter(failedAt: self::at(-1_000));
        $this->store()->store($old);
        $this->store()->store($recent);

        self::assertSame([$recent->id], self::ids($this->store()->list(new DeadLetterFilter(failedFromMs: self::at(-2_000)))));
        self::assertSame([$recent->id, $old->id], self::ids($this->store()->list(new DeadLetterFilter(failedFromMs: self::at(-4_000)))));
        self::assertSame([], $this->store()->list(new DeadLetterFilter(failedFromMs: self::at(0))));
    }

    public function testListFiltersByReplayState(): void
    {
        $replayed = $this->deadLetter(failedAt: self::at(-300));
        $pendingOne = $this->deadLetter(failedAt: self::at(-200));
        $pendingTwo = $this->deadLetter(failedAt: self::at(-100));
        foreach ([$replayed, $pendingOne, $pendingTwo] as $deadLetter) {
            $this->store()->store($deadLetter);
        }
        $this->store()->markReplayed($replayed->id);

        self::assertSame([$pendingTwo->id, $pendingOne->id], self::ids($this->store()->list(new DeadLetterFilter(replayed: false))));
        self::assertSame([$replayed->id], self::ids($this->store()->list(new DeadLetterFilter(replayed: true))));
        self::assertCount(3, $this->store()->list(new DeadLetterFilter()), 'no filter sees both states');
        self::assertCount(3, $this->store()->list(new DeadLetterFilter(replayed: null)));
    }

    public function testListPagesWithLimitAndOffset(): void
    {
        $rows = [];
        foreach ([-500, -400, -300, -200, -100] as $seconds) {
            $rows[] = $this->deadLetter(failedAt: self::at($seconds));
            $this->store()->store($rows[array_key_last($rows)]);
        }
        [$a, $b, $c, $d, $e] = self::ids($rows);

        self::assertSame([$e, $d], self::ids($this->store()->list(new DeadLetterFilter(), limit: 2, offset: 0)));
        self::assertSame([$c, $b], self::ids($this->store()->list(new DeadLetterFilter(), limit: 2, offset: 2)));
        self::assertSame([$a], self::ids($this->store()->list(new DeadLetterFilter(), limit: 2, offset: 4)));
        self::assertSame([], $this->store()->list(new DeadLetterFilter(), limit: 2, offset: 10));
    }

    public function testListReturnsAtMostOneHundredRowsByDefault(): void
    {
        for ($i = 0; $i < 101; ++$i) {
            $this->store()->store($this->deadLetter(failedAt: self::at(-1_000 + $i)));
        }

        self::assertCount(100, $this->store()->list(new DeadLetterFilter()));
        self::assertCount(101, $this->store()->list(new DeadLetterFilter(), limit: 101));
    }

    public function testCountAppliesTheFiltersOfListAndIgnoresPaging(): void
    {
        $replayed = $this->deadLetter(messageName: 'order.placed', source: 'orders_q', failedAt: self::at(-300));
        $this->store()->store($replayed);
        $this->store()->store($this->deadLetter(messageName: 'order.placed', source: 'billing_q', failedAt: self::at(-200)));
        $this->store()->store($this->deadLetter(messageName: 'order.cancelled', source: 'orders_q', failedAt: self::at(-100)));
        $this->store()->markReplayed($replayed->id);

        self::assertSame(3, $this->store()->count(new DeadLetterFilter()));
        self::assertSame(2, $this->store()->count(new DeadLetterFilter(messageName: 'order.placed')));
        self::assertSame(2, $this->store()->count(new DeadLetterFilter(source: 'orders_q')));
        self::assertSame(2, $this->store()->count(new DeadLetterFilter(failedFromMs: self::at(-250))));
        self::assertSame(1, $this->store()->count(new DeadLetterFilter(replayed: true)));
        self::assertSame(2, $this->store()->count(new DeadLetterFilter(replayed: false)));
        self::assertSame(3, $this->store()->count(new DeadLetterFilter(replayed: null)));
        self::assertSame(1, $this->store()->count(new DeadLetterFilter(messageName: 'order.placed', source: 'orders_q')), 'filters combine with AND');
        self::assertSame(0, $this->store()->count(new DeadLetterFilter(messageName: 'unknown.event')));
    }

    public function testMarkReplayedKeepsTheRowForAuditAndTouchesOnlyThatRow(): void
    {
        $replayed = $this->deadLetter(messageId: 'm-1', failedAt: self::at(-200));
        $other = $this->deadLetter(messageId: 'm-2', failedAt: self::at(-100));
        $this->store()->store($replayed);
        $this->store()->store($other);

        $this->store()->markReplayed($replayed->id);

        $found = $this->store()->find($replayed->id);
        self::assertNotNull($found, 'the row stays');
        self::assertNotNull($found->replayedAt);
        self::assertSame('m-1', $found->messageId, 'and keeps its fields');
        self::assertSame($replayed->body, $found->body);
        self::assertNull($this->store()->find($other->id)?->replayedAt);
        self::assertSame(2, $this->store()->count(new DeadLetterFilter()));
    }

    public function testMarkReplayedOfAnOpenRowReturnsTrueAndSetsTheMarkAndKeepsTheOtherFields(): void
    {
        $deadLetter = $this->deadLetter(messageId: 'm-1', headers: [
            'x-flag' => 'v',
        ]);
        $this->store()->store($deadLetter);
        $before = $this->store()->find($deadLetter->id);
        self::assertNotNull($before);
        $now = intdiv(EpochMillis::now(), 1000) * 1000 + 250;

        Clock::set(new MockClock(EpochMillis::toDateTime($now)));
        try {
            self::assertTrue($this->store()->markReplayed($deadLetter->id));
        } finally {
            Clock::set(new NativeClock());
        }

        $found = $this->store()->find($deadLetter->id);
        self::assertNotNull($found);
        self::assertSame($now, $found->replayedAt);
        self::assertEquals(
            [$before->id, $before->source, $before->messageId, $before->messageName, $before->body, $before->headers,
                $before->errorClass, $before->errorMessage, $before->errorTrace, $before->attempts, $before->failedAt,
                $before->replayable],
            [$found->id, $found->source, $found->messageId, $found->messageName, $found->body, $found->headers,
                $found->errorClass, $found->errorMessage, $found->errorTrace, $found->attempts, $found->failedAt,
                $found->replayable],
            'the other fields stay',
        );
    }

    public function testASecondMarkReplayedReturnsFalseAndKeepsTheFirstMark(): void
    {
        $deadLetter = $this->deadLetter();
        $this->store()->store($deadLetter);
        $first = intdiv(EpochMillis::now(), 1000) * 1000 + 100;
        $clock = new MockClock(EpochMillis::toDateTime($first));

        Clock::set($clock);
        try {
            self::assertTrue($this->store()->markReplayed($deadLetter->id));
            $clock->sleep(5);
            self::assertFalse($this->store()->markReplayed($deadLetter->id), 'the row is replayed');
        } finally {
            Clock::set(new NativeClock());
        }

        self::assertSame($first, $this->store()->find($deadLetter->id)?->replayedAt, 'the first mark stays');
    }

    public function testMarkReplayedOfAnUnknownIdReturnsFalse(): void
    {
        $deadLetter = $this->deadLetter();
        $this->store()->store($deadLetter);

        self::assertFalse($this->store()->markReplayed(self::newId()));
        self::assertNull($this->store()->find($deadLetter->id)?->replayedAt, 'no other row changes');
    }

    public function testMarkReplayedOfAValueThatIsNotAUuidReturnsFalseWithNoError(): void
    {
        $deadLetter = $this->deadLetter();
        $this->store()->store($deadLetter);

        self::assertFalse($this->store()->markReplayed('not-a-uuid'));
        self::assertNull($this->store()->find($deadLetter->id)?->replayedAt);
    }

    public function testPurgeWithoutFiltersRemovesEveryRowAndReturnsTheCount(): void
    {
        $replayed = $this->deadLetter(failedAt: self::at(-200));
        $this->store()->store($replayed);
        $this->store()->store($this->deadLetter(failedAt: self::at(-100)));
        $this->store()->markReplayed($replayed->id);

        self::assertSame(2, $this->store()->purge(new DeadLetterFilter()));
        self::assertSame([], $this->store()->list(new DeadLetterFilter()));
        self::assertSame(0, $this->store()->purge(new DeadLetterFilter()), 'nothing is left to remove');
    }

    public function testPurgeRemovesOnlyTheRowsThatMatchEveryFilter(): void
    {
        $placedOld = $this->deadLetter(messageName: 'order.placed', source: 'orders_q', failedAt: self::at(-3_000));
        $placedRecent = $this->deadLetter(messageName: 'order.placed', source: 'orders_q', failedAt: self::at(-1_000));
        $cancelled = $this->deadLetter(messageName: 'order.cancelled', source: 'orders_q', failedAt: self::at(-3_000));
        $billing = $this->deadLetter(messageName: 'order.placed', source: 'billing_q', failedAt: self::at(-3_000));
        foreach ([$placedOld, $placedRecent, $cancelled, $billing] as $deadLetter) {
            $this->store()->store($deadLetter);
        }

        self::assertSame(0, $this->store()->purge(new DeadLetterFilter(messageName: 'unknown.event')), 'no match, no removal');
        self::assertSame(4, $this->store()->count(new DeadLetterFilter()));

        self::assertSame(1, $this->store()->purge(new DeadLetterFilter(messageName: 'order.cancelled')));
        self::assertNull($this->store()->find($cancelled->id));

        self::assertSame(1, $this->store()->purge(new DeadLetterFilter(source: 'billing_q')));
        self::assertNull($this->store()->find($billing->id));

        self::assertSame(0, $this->store()->purge(new DeadLetterFilter(failedUntilMs: self::at(-4_000))), 'nothing is that old');
        self::assertSame(
            1,
            $this->store()->purge(new DeadLetterFilter(messageName: 'order.placed', source: 'orders_q', failedUntilMs: self::at(-2_000))),
            'the three filters combine with AND',
        );
        self::assertSame([$placedRecent->id], self::ids($this->store()->list(new DeadLetterFilter())), 'the recent row stays');
        self::assertSame(1, $this->store()->purge(new DeadLetterFilter(failedUntilMs: self::at(0))));
        self::assertSame(0, $this->store()->count(new DeadLetterFilter()));
    }

    public function testTheMessageNameFilterSelectsAnEqualName(): void
    {
        $placed = $this->deadLetter(messageName: 'order.placed', failedAt: self::at(-200));
        $cancelled = $this->deadLetter(messageName: 'order.cancelled', failedAt: self::at(-100));
        $this->storeAll($placed, $cancelled);

        $this->assertSelects(new DeadLetterFilter(messageName: 'order.placed'), [$placed->id], [$cancelled->id]);
    }

    public function testTheSourceFilterSelectsAnEqualSource(): void
    {
        $orders = $this->deadLetter(source: 'orders_q', failedAt: self::at(-200));
        $billing = $this->deadLetter(source: 'billing_q', failedAt: self::at(-100));
        $this->storeAll($orders, $billing);

        $this->assertSelects(new DeadLetterFilter(source: 'billing_q'), [$billing->id], [$orders->id]);
    }

    public function testTheErrorClassFilterSelectsAnEqualClassName(): void
    {
        $runtime = $this->deadLetter(errorClass: 'RuntimeException', failedAt: self::at(-300));
        $logic = $this->deadLetter(errorClass: 'LogicException', failedAt: self::at(-200));
        $prefix = $this->deadLetter(errorClass: 'LogicExceptionAndMore', failedAt: self::at(-100));
        $this->storeAll($runtime, $logic, $prefix);

        $this->assertSelects(
            new DeadLetterFilter(errorClass: 'LogicException'),
            [$logic->id],
            [$prefix->id, $runtime->id],
        );
    }

    public function testTheMessageIdFilterSelectsAnEqualMessageId(): void
    {
        $first = $this->deadLetter(messageId: 'm-1', failedAt: self::at(-200));
        $second = $this->deadLetter(messageId: 'm-2', failedAt: self::at(-100));
        $this->storeAll($first, $second);

        $this->assertSelects(new DeadLetterFilter(messageId: 'm-1'), [$first->id], [$second->id]);
    }

    public function testTheFailedFromBoundIsInclusive(): void
    {
        $bound = self::at(-100);
        $before = $this->deadLetter(failedAt: $bound - 1);
        $at = $this->deadLetter(failedAt: $bound);
        $after = $this->deadLetter(failedAt: $bound + 1);
        $this->storeAll($before, $at, $after);

        $this->assertSelects(new DeadLetterFilter(failedFromMs: $bound), [$after->id, $at->id], [$before->id]);
    }

    public function testTheFailedUntilBoundIsExclusive(): void
    {
        $bound = self::at(-100);
        $before = $this->deadLetter(failedAt: $bound - 1);
        $at = $this->deadLetter(failedAt: $bound);
        $after = $this->deadLetter(failedAt: $bound + 1);
        $this->storeAll($before, $at, $after);

        $this->assertSelects(new DeadLetterFilter(failedUntilMs: $bound), [$before->id], [$after->id, $at->id]);
    }

    public function testTheMinAttemptsFilterIsInclusive(): void
    {
        $two = $this->deadLetter(attempts: 2, failedAt: self::at(-300));
        $three = $this->deadLetter(attempts: 3, failedAt: self::at(-200));
        $four = $this->deadLetter(attempts: 4, failedAt: self::at(-100));
        $this->storeAll($two, $three, $four);

        $this->assertSelects(new DeadLetterFilter(minAttempts: 3), [$four->id, $three->id], [$two->id]);
    }

    /** @return iterable<string, array{bool|null, list<string>, list<string>}> */
    public static function stateFilters(): iterable
    {
        yield 'true' => [true, ['first'], ['second']];
        yield 'false' => [false, ['second'], ['first']];
        yield 'null' => [null, ['second', 'first'], []];
    }

    /**
     * @param list<string> $selected
     * @param list<string> $kept
     */
    #[DataProvider('stateFilters')]
    public function testTheReplayedFilter(?bool $replayed, array $selected, array $kept): void
    {
        $rows = [
            'first' => $this->deadLetter(failedAt: self::at(-200)),
            'second' => $this->deadLetter(failedAt: self::at(-100)),
        ];
        $this->storeAll(...array_values($rows));
        $this->store()->markReplayed($rows['first']->id);

        $this->assertSelects(
            new DeadLetterFilter(replayed: $replayed),
            self::idsOf($rows, $selected),
            self::idsOf($rows, $kept),
        );
    }

    /**
     * @param list<string> $selected
     * @param list<string> $kept
     */
    #[DataProvider('stateFilters')]
    public function testTheReplayableFilter(?bool $replayable, array $selected, array $kept): void
    {
        $rows = [
            'first' => $this->deadLetter(failedAt: self::at(-200), replayable: true),
            'second' => $this->deadLetter(failedAt: self::at(-100), replayable: false),
        ];
        $this->storeAll(...array_values($rows));

        $this->assertSelects(
            new DeadLetterFilter(replayable: $replayable),
            self::idsOf($rows, $selected),
            self::idsOf($rows, $kept),
        );
    }

    /**
     * Each other row differs from the match in one criterion only. The rows
     * of one pair are replayed one after the other (a replayed row is outside
     * the open pair rule), so the filter selects replayed rows.
     */
    public function testAllCriteriaCombineWithAnd(): void
    {
        $from = self::at(-1_000);
        $until = self::at(-100);
        $row = fn (
            string $messageName = 'order.placed',
            string $source = 'orders_q',
            string $messageId = 'm-match',
            string $errorClass = 'LogicException',
            int $attempts = 5,
            ?int $failedAt = null,
            bool $replayable = false,
        ): DeadLetter => $this->deadLetter(
            messageName: $messageName,
            source: $source,
            failedAt: $failedAt ?? $from + 10,
            messageId: $messageId,
            replayable: $replayable,
            errorClass: $errorClass,
            attempts: $attempts,
        );
        $match = $row();
        $replayedOthers = [
            $row(messageName: 'order.cancelled'),
            $row(source: 'billing_q'),
            $row(errorClass: 'RuntimeException'),
            $row(messageId: 'm-other'),
            $row(failedAt: $from - 1),
            $row(failedAt: $until),
            $row(attempts: 4),
            $row(replayable: true),
        ];
        foreach ([$match, ...$replayedOthers] as $deadLetter) {
            $this->store()->store($deadLetter);
            $this->store()->markReplayed($deadLetter->id);
        }
        $open = $row(failedAt: $from + 20);
        $this->store()->store($open);
        $filter = new DeadLetterFilter(
            messageName: 'order.placed',
            source: 'orders_q',
            errorClass: 'LogicException',
            messageId: 'm-match',
            failedFromMs: $from,
            failedUntilMs: $until,
            minAttempts: 5,
            replayed: true,
            replayable: false,
        );

        self::assertSame([$match->id], self::ids($this->store()->list($filter)));
        self::assertSame(1, $this->store()->count($filter));
        self::assertSame([$match->id], $this->store()->ids($filter, 100));
        self::assertSame(1, $this->store()->purge($filter));
        self::assertSame(9, $this->store()->count(new DeadLetterFilter()));
        self::assertNull($this->store()->find($match->id));
    }

    public function testIdsAreOldestFirstAndThenByIdAscending(): void
    {
        // One value for the two rows with the same time: two reads of the clock can differ by a millisecond.
        $sameTime = self::at(-100);
        $rows = [
            $this->deadLetter(id: '01900000-0000-7000-8000-000000000003', failedAt: self::at(-300)),
            $this->deadLetter(id: '01900000-0000-7000-8000-000000000002', failedAt: $sameTime),
            $this->deadLetter(id: '01900000-0000-7000-8000-000000000001', failedAt: $sameTime),
        ];
        $this->storeAll(...$rows);

        self::assertSame(
            ['01900000-0000-7000-8000-000000000003', '01900000-0000-7000-8000-000000000001', '01900000-0000-7000-8000-000000000002'],
            $this->store()->ids(new DeadLetterFilter(), 10),
        );
        self::assertSame(
            ['01900000-0000-7000-8000-000000000002', '01900000-0000-7000-8000-000000000001', '01900000-0000-7000-8000-000000000003'],
            self::ids($this->store()->list(new DeadLetterFilter())),
            'list is the reverse: newest first, then by id descending',
        );
    }

    public function testIdsReturnAtMostTheLimitAndApplyTheFilter(): void
    {
        $first = $this->deadLetter(source: 'orders_q', failedAt: self::at(-400));
        $second = $this->deadLetter(source: 'orders_q', failedAt: self::at(-300));
        $billing = $this->deadLetter(source: 'billing_q', failedAt: self::at(-200));
        $third = $this->deadLetter(source: 'orders_q', failedAt: self::at(-100));
        $this->storeAll($first, $second, $billing, $third);

        self::assertSame([$first->id, $second->id], $this->store()->ids(new DeadLetterFilter(source: 'orders_q'), 2));
        self::assertSame([$first->id], $this->store()->ids(new DeadLetterFilter(source: 'orders_q'), 1));
        self::assertSame(
            [$first->id, $second->id, $third->id],
            $this->store()->ids(new DeadLetterFilter(source: 'orders_q'), 3),
        );
        self::assertSame([], $this->store()->ids(new DeadLetterFilter(source: 'unknown_q'), 10), 'no match');
    }

    /** @return iterable<string, array{int}> */
    public static function limitsBelowOne(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('limitsBelowOne')]
    public function testIdsWithALimitBelowOneThrow(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("limit must be at least 1, got {$limit}");

        $this->store()->ids(new DeadLetterFilter(), $limit);
    }

    public function testStatsGiveOneEntryForEachGroupWithTheCountAndTheOldestAndNewestFailure(): void
    {
        $oldest = $this->deadLetter(messageName: 'order.placed', failedAt: self::at(-300));
        $other = $this->deadLetter(messageName: 'order.cancelled', failedAt: self::at(-200));
        $newest = $this->deadLetter(messageName: 'order.placed', failedAt: self::at(-100));
        $this->storeAll($oldest, $other, $newest);

        self::assertEquals([
            new DeadLetterStat('order.placed', 'orders_q', 'RuntimeException', 2, $oldest->failedAt, $newest->failedAt),
            new DeadLetterStat('order.cancelled', 'orders_q', 'RuntimeException', 1, $other->failedAt, $other->failedAt),
        ], $this->store()->stats(new DeadLetterFilter()));
    }

    public function testStatsOfGroupsWithEqualCountsAreByMessageNameThenSourceThenErrorClass(): void
    {
        $at = self::at(-100);
        $this->storeAll(
            $this->deadLetter(messageName: 'b.event', source: 'a_q', errorClass: 'LogicException', failedAt: $at),
            $this->deadLetter(messageName: 'a.event', source: 'b_q', errorClass: 'LogicException', failedAt: $at),
            $this->deadLetter(messageName: 'a.event', source: 'a_q', errorClass: 'RuntimeException', failedAt: $at),
            $this->deadLetter(messageName: 'a.event', source: 'a_q', errorClass: 'LogicException', failedAt: $at),
        );

        self::assertEquals([
            new DeadLetterStat('a.event', 'a_q', 'LogicException', 1, $at, $at),
            new DeadLetterStat('a.event', 'a_q', 'RuntimeException', 1, $at, $at),
            new DeadLetterStat('a.event', 'b_q', 'LogicException', 1, $at, $at),
            new DeadLetterStat('b.event', 'a_q', 'LogicException', 1, $at, $at),
        ], $this->store()->stats(new DeadLetterFilter()));
    }

    public function testStatsApplyTheFilter(): void
    {
        $orders = $this->deadLetter(source: 'orders_q', failedAt: self::at(-200));
        $this->storeAll($orders, $this->deadLetter(source: 'billing_q'), $this->deadLetter(source: 'billing_q'));

        self::assertEquals([
            new DeadLetterStat('order.placed', 'orders_q', 'RuntimeException', 1, $orders->failedAt, $orders->failedAt),
        ], $this->store()->stats(new DeadLetterFilter(source: 'orders_q')));
        self::assertSame([], $this->store()->stats(new DeadLetterFilter(source: 'unknown_q')));
    }

    public function testStatsOfAnEmptyStoreAreEmpty(): void
    {
        self::assertSame([], $this->store()->stats(new DeadLetterFilter()));
    }

    final protected function store(): DeadLetterStore
    {
        return $this->store ??= $this->createStore();
    }

    /** @param array<string, mixed> $headers */
    private function deadLetter(
        string $messageName = 'order.placed',
        string $source = 'orders_q',
        ?int $failedAt = null,
        ?string $messageId = null,
        array $headers = [],
        bool $replayable = true,
        ?string $id = null,
        string $errorClass = 'RuntimeException',
        int $attempts = 3,
    ): DeadLetter {
        return new DeadLetter(
            id: $id ?? self::newId(),
            source: $source,
            messageId: $messageId ?? 'msg-'.self::newId(), // a new message by default: no upsert
            messageName: $messageName,
            body: '{"some":"bytes"}',
            headers: $headers,
            errorClass: $errorClass,
            errorMessage: 'boom',
            errorTrace: '#0 trace-line',
            attempts: $attempts,
            failedAt: $failedAt ?? self::at(-10),
            replayable: $replayable,
        );
    }

    private function storeAll(DeadLetter ...$deadLetters): void
    {
        foreach ($deadLetters as $deadLetter) {
            $this->store()->store($deadLetter);
        }
    }

    /**
     * The filter selects the same rows in list (newest first), count, ids
     * (oldest first) and purge; the purge keeps the other rows.
     *
     * @param list<string> $selected the ids of the matching rows, newest first
     * @param list<string> $kept     the ids of the other rows, newest first
     */
    private function assertSelects(DeadLetterFilter $filter, array $selected, array $kept): void
    {
        self::assertSame($selected, self::ids($this->store()->list($filter)), 'list');
        self::assertSame(count($selected), $this->store()->count($filter), 'count');
        self::assertSame(array_reverse($selected), $this->store()->ids($filter, 100), 'ids');
        self::assertSame(count($selected), $this->store()->purge($filter), 'purge');
        self::assertSame($kept, self::ids($this->store()->list(new DeadLetterFilter())), 'the purge keeps the other rows');
    }

    /**
     * @param array<string, DeadLetter> $rows
     * @param list<string>              $names
     *
     * @return list<string>
     */
    private static function idsOf(array $rows, array $names): array
    {
        return array_map(static fn (string $name): string => $rows[$name]->id, $names);
    }

    /** An instant that lies the given number of seconds from now (negative: in the past). */
    private static function at(int $seconds): int
    {
        return EpochMillis::now() + $seconds * 1000;
    }

    /**
     * @param array<array-key, DeadLetter> $deadLetters
     *
     * @return list<string>
     */
    private static function ids(array $deadLetters): array
    {
        return array_values(array_map(static fn (DeadLetter $d): string => $d->id, $deadLetters));
    }

    private static function newId(): string
    {
        return Uuid::v7()->toString();
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
