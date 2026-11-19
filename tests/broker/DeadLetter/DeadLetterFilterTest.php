<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\DeadLetter;

use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStat;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeadLetterFilterTest extends TestCase
{
    public function testEveryCriterionIsNullByDefaultAndTheFilterIsEmpty(): void
    {
        $filter = new DeadLetterFilter();

        self::assertNull($filter->messageName);
        self::assertNull($filter->source);
        self::assertNull($filter->errorClass);
        self::assertNull($filter->messageId);
        self::assertNull($filter->failedFromMs);
        self::assertNull($filter->failedUntilMs);
        self::assertNull($filter->minAttempts);
        self::assertNull($filter->replayed);
        self::assertNull($filter->replayable);
        self::assertTrue($filter->isEmpty());
    }

    public function testTheConstructorKeepsEachCriterion(): void
    {
        $filter = self::full();

        self::assertSame('order.placed', $filter->messageName);
        self::assertSame('orders_q', $filter->source);
        self::assertSame('RuntimeException', $filter->errorClass);
        self::assertSame('m-1', $filter->messageId);
        self::assertSame(1_000, $filter->failedFromMs);
        self::assertSame(2_000, $filter->failedUntilMs);
        self::assertSame(3, $filter->minAttempts);
        self::assertTrue($filter->replayed);
        self::assertFalse($filter->replayable);
    }

    /** @return iterable<string, array{DeadLetterFilter}> */
    public static function oneCriterion(): iterable
    {
        yield 'messageName' => [new DeadLetterFilter(messageName: 'order.placed')];
        yield 'source' => [new DeadLetterFilter(source: 'orders_q')];
        yield 'errorClass' => [new DeadLetterFilter(errorClass: 'RuntimeException')];
        yield 'messageId' => [new DeadLetterFilter(messageId: 'm-1')];
        yield 'failedFromMs' => [new DeadLetterFilter(failedFromMs: 0)];
        yield 'failedUntilMs' => [new DeadLetterFilter(failedUntilMs: 0)];
        yield 'minAttempts' => [new DeadLetterFilter(minAttempts: 0)];
        yield 'replayed false' => [new DeadLetterFilter(replayed: false)];
        yield 'replayable false' => [new DeadLetterFilter(replayable: false)];
        yield 'empty message name' => [new DeadLetterFilter(messageName: '')];
    }

    #[DataProvider('oneCriterion')]
    public function testOneSetCriterionMakesTheFilterNotEmpty(DeadLetterFilter $filter): void
    {
        self::assertFalse($filter->isEmpty());
    }

    public function testWithReplayedReturnsACopyWithOnlyTheReplayStateChanged(): void
    {
        $filter = self::full();

        $copy = $filter->withReplayed(false);

        self::assertNotSame($filter, $copy);
        self::assertTrue($filter->replayed, 'the original does not change');
        self::assertFalse($copy->replayed);
        self::assertSame('order.placed', $copy->messageName);
        self::assertSame('orders_q', $copy->source);
        self::assertSame('RuntimeException', $copy->errorClass);
        self::assertSame('m-1', $copy->messageId);
        self::assertSame(1_000, $copy->failedFromMs);
        self::assertSame(2_000, $copy->failedUntilMs);
        self::assertSame(3, $copy->minAttempts);
        self::assertFalse($copy->replayable);
        self::assertNull($filter->withReplayed(null)->replayed);
        self::assertTrue((new DeadLetterFilter())->withReplayed(true)->replayed);
        self::assertTrue((new DeadLetterFilter())->withReplayed(null)->isEmpty());
    }

    public function testMinAttemptsBelowZeroThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minAttempts must be 0 or more, got -1');

        new DeadLetterFilter(minAttempts: -1);
    }

    /** @return iterable<string, array{int, int}> */
    public static function emptyWindows(): iterable
    {
        yield 'from equal to until' => [1_000, 1_000];
        yield 'from after until' => [1_001, 1_000];
    }

    #[DataProvider('emptyWindows')]
    public function testAFailedFromBoundAtOrAfterTheUntilBoundThrows(int $from, int $until): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("failedFromMs ({$from}) must be lower than failedUntilMs ({$until})");

        new DeadLetterFilter(failedFromMs: $from, failedUntilMs: $until);
    }

    public function testAFromBoundOneBelowTheUntilBoundIsValid(): void
    {
        $filter = new DeadLetterFilter(failedFromMs: 999, failedUntilMs: 1_000);

        self::assertSame(999, $filter->failedFromMs);
        self::assertSame(1_000, $filter->failedUntilMs);
    }

    public function testTheStatKeepsEachValue(): void
    {
        $stat = new DeadLetterStat('order.placed', 'orders_q', 'RuntimeException', 2, 1_000, 2_000);

        self::assertSame('order.placed', $stat->messageName);
        self::assertSame('orders_q', $stat->source);
        self::assertSame('RuntimeException', $stat->errorClass);
        self::assertSame(2, $stat->count);
        self::assertSame(1_000, $stat->oldestFailedAt);
        self::assertSame(2_000, $stat->newestFailedAt);
    }

    private static function full(): DeadLetterFilter
    {
        return new DeadLetterFilter(
            messageName: 'order.placed',
            source: 'orders_q',
            errorClass: 'RuntimeException',
            messageId: 'm-1',
            failedFromMs: 1_000,
            failedUntilMs: 2_000,
            minAttempts: 3,
            replayed: true,
            replayable: false,
        );
    }
}
