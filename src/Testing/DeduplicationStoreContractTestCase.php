<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests of the {@see DeduplicationStore} port. An adapter extends this
 * class, returns its store from createStore(), and inherits the tests.
 *
 * Rules for the adapter:
 *  - createStore() returns a store that is EMPTY at the start of each test.
 *  - The store dates an entry with the time of acquire() on the clock of
 *    symfony/clock (EpochMillis::now()). The tests of the age use that clock and
 *    short waits of about 30 ms, so the store must keep milliseconds.
 */
abstract class DeduplicationStoreContractTestCase extends TestCase
{
    private ?DeduplicationStore $store = null;

    /** A store that is empty at the start of the test. */
    abstract protected function createStore(): DeduplicationStore;

    public function testTheFirstAcquireWinsAndASecondAcquireIsADuplicate(): void
    {
        $message = self::incoming('m-1');

        self::assertTrue($this->store()->acquire($message, 'orders_consumer'));
        self::assertFalse($this->store()->acquire($message, 'orders_consumer'), 'the duplicate is rejected');
        self::assertFalse($this->store()->acquire($message, 'orders_consumer'), 'and it stays rejected');
    }

    public function testADuplicateIsTheSameMessageIdNotTheSameMessageName(): void
    {
        self::assertTrue($this->store()->acquire(self::incoming('m-1'), 'orders_consumer'));

        self::assertTrue($this->store()->acquire(self::incoming('m-2'), 'orders_consumer'), 'another message id');
        self::assertFalse(
            $this->store()->acquire(self::incoming('m-1', messageName: 'order.cancelled'), 'orders_consumer'),
            'the same message id with another name',
        );
    }

    public function testDeduplicationIsScopedPerConsumer(): void
    {
        $message = self::incoming('m-1');

        self::assertTrue($this->store()->acquire($message, 'orders_consumer'));
        self::assertTrue($this->store()->acquire($message, 'other_consumer'), 'another consumer has its own entry');
        self::assertFalse($this->store()->acquire($message, 'other_consumer'));
    }

    public function testCleanupRemovesOnlyEntriesCreatedBeforeTheInstant(): void
    {
        $this->store()->acquire(self::incoming('m-old-1'), 'orders_consumer');
        $this->store()->acquire(self::incoming('m-old-2'), 'other_consumer');
        usleep(30_000);
        $threshold = EpochMillis::now();
        usleep(30_000);
        $this->store()->acquire(self::incoming('m-fresh'), 'orders_consumer');

        $removed = $this->store()->cleanup($threshold);

        self::assertSame(2, $removed);
        self::assertFalse($this->store()->acquire(self::incoming('m-fresh'), 'orders_consumer'), 'a fresh entry survives');
        self::assertTrue($this->store()->acquire(self::incoming('m-old-1'), 'orders_consumer'), 'an old entry is gone');
        self::assertTrue($this->store()->acquire(self::incoming('m-old-2'), 'other_consumer'), 'in every scope');
    }

    public function testCleanupBeforeTheFirstEntryRemovesNothing(): void
    {
        $threshold = EpochMillis::now() - 60_000;
        $this->store()->acquire(self::incoming('m-1'), 'orders_consumer');

        self::assertSame(0, $this->store()->cleanup($threshold));
        self::assertFalse($this->store()->acquire(self::incoming('m-1'), 'orders_consumer'));
    }

    public function testCleanupAfterTheLastEntryRemovesEverything(): void
    {
        $this->store()->acquire(self::incoming('m-1'), 'orders_consumer');
        $this->store()->acquire(self::incoming('m-2'), 'orders_consumer');
        usleep(30_000);

        self::assertSame(2, $this->store()->cleanup(EpochMillis::now()));
        self::assertSame(0, $this->store()->cleanup(EpochMillis::now()), 'a second cleanup finds nothing');
    }

    public function testCountOlderThanCountsTheEntriesCleanupWouldRemoveAndRemovesNone(): void
    {
        $this->store()->acquire(self::incoming('m-old-1'), 'orders_consumer');
        $this->store()->acquire(self::incoming('m-old-2'), 'orders_consumer');
        usleep(30_000);
        $threshold = EpochMillis::now();
        usleep(30_000);
        $this->store()->acquire(self::incoming('m-fresh'), 'orders_consumer');

        self::assertSame(2, $this->store()->countOlderThan($threshold));
        self::assertSame(2, $this->store()->countOlderThan($threshold), 'counting is a dry run');
        self::assertSame(0, $this->store()->countOlderThan(EpochMillis::now() - 60_000));
        self::assertSame(3, $this->store()->countOlderThan(EpochMillis::now() + 60_000));
        self::assertSame(2, $this->store()->cleanup($threshold), 'the count equals the removed rows');
    }

    final protected function store(): DeduplicationStore
    {
        return $this->store ??= $this->createStore();
    }

    private static function incoming(string $messageId, string $messageName = 'order.placed'): IncomingMessage
    {
        return new IncomingMessage(
            messageId: $messageId,
            messageName: $messageName,
            createdAt: EpochMillis::now(),
            payload: [],
        );
    }
}
