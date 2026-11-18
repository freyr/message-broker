<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;
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
    }

    public function testStoresTextThatEachSinkCanStore(): void
    {
        $fffd = "\u{FFFD}";
        $body = "raw\x00bytes\xff";
        $deadLetter = new DeadLetter(
            id: self::newId(),
            source: 'orders_q',
            messageId: 'msg-1',
            messageName: str_repeat('n', 300),
            body: $body,
            headers: [
                'x-invalid' => "a\xffb",
                'x-nul' => "a\x00b",
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
        self::assertSame(str_repeat('n', 255), $found->messageName);
        self::assertSame(self::sortKeys([
            'x-invalid' => "a{$fffd}b",
            'x-nul' => "a{$fffd}b",
            'x-nested' => [
                'deep' => "c{$fffd}d",
            ],
        ]), self::sortKeys($found->headers));
        self::assertSame("bad {$fffd} text {$fffd} here", $found->errorMessage);
        self::assertSame($body, $found->body, 'the body stays the exact bytes');
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
            self::ids($this->store()->list()),
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

        self::assertSame([$billing->id, $placed->id], self::ids($this->store()->list(messageName: 'order.placed')));
        self::assertSame([$cancelled->id, $placed->id], self::ids($this->store()->list(source: 'orders_q')));
        self::assertSame(
            [$placed->id],
            self::ids($this->store()->list(messageName: 'order.placed', source: 'orders_q')),
            'the filters combine with AND',
        );
        self::assertSame([], $this->store()->list(messageName: 'unknown.event'));
        self::assertCount(3, $this->store()->list(), 'no filter sees every row');
    }

    public function testListFiltersByALowerFailedAtBound(): void
    {
        $old = $this->deadLetter(failedAt: self::at(-3_000));
        $recent = $this->deadLetter(failedAt: self::at(-1_000));
        $this->store()->store($old);
        $this->store()->store($recent);

        self::assertSame([$recent->id], self::ids($this->store()->list(sinceMs: self::at(-2_000))));
        self::assertSame([$recent->id, $old->id], self::ids($this->store()->list(sinceMs: self::at(-4_000))));
        self::assertSame([], $this->store()->list(sinceMs: self::at(0)));
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

        self::assertSame([$pendingTwo->id, $pendingOne->id], self::ids($this->store()->list(replayed: false)));
        self::assertSame([$replayed->id], self::ids($this->store()->list(replayed: true)));
        self::assertCount(3, $this->store()->list(), 'no filter sees both states');
        self::assertCount(3, $this->store()->list(replayed: null));
    }

    public function testListPagesWithLimitAndOffset(): void
    {
        $rows = [];
        foreach ([-500, -400, -300, -200, -100] as $seconds) {
            $rows[] = $this->deadLetter(failedAt: self::at($seconds));
            $this->store()->store($rows[array_key_last($rows)]);
        }
        [$a, $b, $c, $d, $e] = self::ids($rows);

        self::assertSame([$e, $d], self::ids($this->store()->list(limit: 2, offset: 0)));
        self::assertSame([$c, $b], self::ids($this->store()->list(limit: 2, offset: 2)));
        self::assertSame([$a], self::ids($this->store()->list(limit: 2, offset: 4)));
        self::assertSame([], $this->store()->list(limit: 2, offset: 10));
    }

    public function testListReturnsAtMostOneHundredRowsByDefault(): void
    {
        for ($i = 0; $i < 101; ++$i) {
            $this->store()->store($this->deadLetter(failedAt: self::at(-1_000 + $i)));
        }

        self::assertCount(100, $this->store()->list());
        self::assertCount(101, $this->store()->list(limit: 101));
    }

    public function testCountAppliesTheFiltersOfListAndIgnoresPaging(): void
    {
        $replayed = $this->deadLetter(messageName: 'order.placed', source: 'orders_q', failedAt: self::at(-300));
        $this->store()->store($replayed);
        $this->store()->store($this->deadLetter(messageName: 'order.placed', source: 'billing_q', failedAt: self::at(-200)));
        $this->store()->store($this->deadLetter(messageName: 'order.cancelled', source: 'orders_q', failedAt: self::at(-100)));
        $this->store()->markReplayed($replayed->id);

        self::assertSame(3, $this->store()->count());
        self::assertSame(2, $this->store()->count(messageName: 'order.placed'));
        self::assertSame(2, $this->store()->count(source: 'orders_q'));
        self::assertSame(2, $this->store()->count(sinceMs: self::at(-250)));
        self::assertSame(1, $this->store()->count(replayed: true));
        self::assertSame(2, $this->store()->count(replayed: false));
        self::assertSame(3, $this->store()->count(replayed: null));
        self::assertSame(1, $this->store()->count(messageName: 'order.placed', source: 'orders_q'), 'filters combine with AND');
        self::assertSame(0, $this->store()->count(messageName: 'unknown.event'));
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
        self::assertSame(2, $this->store()->count());
    }

    public function testPurgeWithoutFiltersRemovesEveryRowAndReturnsTheCount(): void
    {
        $replayed = $this->deadLetter(failedAt: self::at(-200));
        $this->store()->store($replayed);
        $this->store()->store($this->deadLetter(failedAt: self::at(-100)));
        $this->store()->markReplayed($replayed->id);

        self::assertSame(2, $this->store()->purge());
        self::assertSame([], $this->store()->list());
        self::assertSame(0, $this->store()->purge(), 'nothing is left to remove');
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

        self::assertSame(0, $this->store()->purge(messageName: 'unknown.event'), 'no match, no removal');
        self::assertSame(4, $this->store()->count());

        self::assertSame(1, $this->store()->purge(messageName: 'order.cancelled'));
        self::assertNull($this->store()->find($cancelled->id));

        self::assertSame(1, $this->store()->purge(source: 'billing_q'));
        self::assertNull($this->store()->find($billing->id));

        self::assertSame(0, $this->store()->purge(olderThanMs: self::at(-4_000)), 'nothing is that old');
        self::assertSame(
            1,
            $this->store()->purge(messageName: 'order.placed', source: 'orders_q', olderThanMs: self::at(-2_000)),
            'the three filters combine with AND',
        );
        self::assertSame([$placedRecent->id], self::ids($this->store()->list()), 'the recent row stays');
        self::assertSame(1, $this->store()->purge(olderThanMs: self::at(0)));
        self::assertSame(0, $this->store()->count());
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
        string $messageId = 'msg-1',
        array $headers = [],
    ): DeadLetter {
        return new DeadLetter(
            id: self::newId(),
            source: $source,
            messageId: $messageId,
            messageName: $messageName,
            body: '{"some":"bytes"}',
            headers: $headers,
            errorClass: 'RuntimeException',
            errorMessage: 'boom',
            errorTrace: '#0 trace-line',
            attempts: 3,
            failedAt: $failedAt ?? self::at(-10),
        );
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
