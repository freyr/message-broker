<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\FailingRow;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use Freyr\MessageBroker\Testing\InMemoryTransaction;
use Freyr\MessageBroker\Time\EpochMillis;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The fake keys its rows by (id, createdAt), as the primary key of outbox_messages
 * is (id, created_at): a replay of a published message adds a second row with the
 * same id. The helpers that take only an id address the row with the latest createdAt.
 */
final class InMemoryOutboxStoreTest extends TestCase
{
    private const string ID = '0190a1b2-0000-7000-8000-000000000001';

    public function testTwoRowsWithOneIdAndTwoCreatedAtValuesAreBothClaimed(): void
    {
        $store = new InMemoryOutboxStore();
        $original = self::record(self::ID, 5_000);
        $replay = self::record(self::ID, 1_000);
        $store->insert($original);
        $store->insert($replay);

        $claimed = [];
        $published = $store->drainClaimed('default', 10, static function (array $records) use (&$claimed): ClaimOutcome {
            $claimed = $records;

            return ClaimOutcome::published([self::ID]);
        });

        self::assertSame([$original, $replay], $claimed, 'the second row does not overwrite the first');
        self::assertSame(1, $published, 'the count of the outcome ids');
        self::assertSame([], $store->unpublishedIds(), 'the id of the outcome marks each claimed row with that id');
        self::assertSame(0, $store->drainClaimed('default', 10, static fn (): never => throw new LogicException('no row is left')));
    }

    public function testARetryOfAnIdAppliesToEachClaimedRowWithThatId(): void
    {
        $store = new InMemoryOutboxStore();
        $store->insert(self::record(self::ID, 5_000));
        $store->insert(self::record(self::ID, 1_000));
        $retryAt = EpochMillis::now() + 60_000;

        $store->drainClaimed('default', 10, static fn (): ClaimOutcome => ClaimOutcome::retryAll([
            self::ID => $retryAt,
        ]));

        $due = [];
        $store->drainClaimed('default', 10, static function (array $records) use (&$due): ClaimOutcome {
            $due = $records;

            return ClaimOutcome::published([]);
        });
        self::assertSame([], $due, 'both rows wait for the retry time');
        self::assertSame(1, $store->row(self::ID)->attempts);
        self::assertSame($retryAt, $store->row(self::ID)->availableAt);
    }

    public function testTheIdHelpersAddressTheRowWithTheLatestCreatedAt(): void
    {
        $store = new InMemoryOutboxStore();
        $older = self::record(self::ID, 5_000);
        $newer = self::record(self::ID, 1_000);
        $other = self::record('0190a1b2-0000-7000-8000-000000000002', 3_000);
        $store->insert($newer);
        $store->insert($older);
        $store->insert($other);

        self::assertSame($newer, $store->row(self::ID), 'the latest row, also when it was inserted first');
        self::assertSame([self::ID, $other->id], $store->unpublishedIds(), 'each id once, in the order of the first insert');

        $store->drainClaimed('default', 10, static fn (): ClaimOutcome => ClaimOutcome::published([self::ID]));

        self::assertTrue($store->isPublished(self::ID));
        self::assertFalse($store->isPublished($other->id));
        self::assertFalse($store->isPublished('0190a1b2-0000-7000-8000-00000000ffff'), 'an unknown id');
        self::assertSame([$other->id], $store->unpublishedIds());
    }

    public function testIsPublishedIsFalseWhileTheLatestRowOfTheIdIsNotPublished(): void
    {
        $store = new InMemoryOutboxStore();
        $store->insert(self::record(self::ID, 5_000));
        $store->drainClaimed('default', 10, static fn (): ClaimOutcome => ClaimOutcome::published([self::ID]));
        self::assertTrue($store->isPublished(self::ID));

        $store->insert(self::record(self::ID, 1_000));

        self::assertFalse($store->isPublished(self::ID), 'the replay is not published yet');
        self::assertSame([self::ID], $store->unpublishedIds());
    }

    public function testAnInsertWithTheSameIdAndCreatedAtReplacesTheRow(): void
    {
        $store = new InMemoryOutboxStore();
        $first = self::record(self::ID, 5_000);
        $second = new OutboxRecord(self::ID, 'default', 'order.cancelled', '{}', [], $first->createdAt);
        $store->insert($first);
        $store->insert($second);

        self::assertSame($second, $store->row(self::ID));
        self::assertSame([self::ID], $store->unpublishedIds());
    }

    public function testARollBackRestoresBothRowsAndTheirPublishedState(): void
    {
        $transaction = new InMemoryTransaction();
        $store = new InMemoryOutboxStore($transaction);
        $original = self::record(self::ID, 5_000);
        $store->insert($original);
        $store->drainClaimed('default', 10, static fn (): ClaimOutcome => ClaimOutcome::published([self::ID]));

        $transaction->begin();
        $store->insert(self::record(self::ID, 1_000));
        $transaction->rollBack();

        self::assertSame($original, $store->row(self::ID));
        self::assertTrue($store->isPublished(self::ID));
        self::assertSame([], $store->unpublishedIds());
    }

    public function testAnOutcomeDoesNotChangeARowThatWasAbandonedDuringTheClaim(): void
    {
        $store = new InMemoryOutboxStore();
        $published = self::record(self::ID, 1_000);
        $retried = self::record('0190a1b2-0000-7000-8000-000000000002', 1_000);
        $store->insert($published);
        $store->insert($retried);

        $count = $store->drainClaimed('default', 10, static function () use ($store, $published, $retried): ClaimOutcome {
            self::assertSame(1, $store->abandon($published->id));
            self::assertSame(1, $store->abandon($retried->id));

            return new ClaimOutcome([$published->id], [
                $retried->id => EpochMillis::now() + 60_000,
            ]);
        });

        self::assertSame(1, $count, 'the count of the outcome ids');
        self::assertFalse($store->isPublished($published->id), 'the abandoned row is not marked published');
        self::assertTrue($store->isAbandoned($published->id));
        self::assertSame($retried, $store->row($retried->id), 'the abandoned row gets no attempt and no new time');
        self::assertSame([$published->id, $retried->id], $store->unpublishedIds(), 'an abandoned row is not published');
    }

    public function testIsAbandonedAddressesTheRowWithTheLatestCreatedAt(): void
    {
        $store = new InMemoryOutboxStore();
        $store->insert(self::record(self::ID, 5_000));
        self::assertSame(1, $store->abandon(self::ID));
        self::assertTrue($store->isAbandoned(self::ID));

        $store->insert(self::record(self::ID, 1_000));

        self::assertFalse($store->isAbandoned(self::ID), 'the replay is not abandoned');
        self::assertFalse($store->isAbandoned('0190a1b2-0000-7000-8000-00000000ffff'), 'an unknown id');
    }

    public function testARollBackRestoresTheAbandonMark(): void
    {
        $transaction = new InMemoryTransaction();
        $store = new InMemoryOutboxStore($transaction);
        $kept = self::record(self::ID, 5_000);
        $undone = self::record('0190a1b2-0000-7000-8000-000000000002', 5_000);
        $store->insert($kept);
        $store->insert($undone);
        self::assertSame(1, $store->abandon($kept->id));

        $transaction->begin();
        self::assertSame(1, $store->abandon($undone->id));
        $transaction->rollBack();

        self::assertTrue($store->isAbandoned($kept->id), 'a mark before begin() stays');
        self::assertFalse($store->isAbandoned($undone->id), 'a mark inside the transaction rolls back');
        $claimed = [];
        $store->drainClaimed('default', 10, static function (array $records) use (&$claimed): ClaimOutcome {
            $claimed = $records;

            return ClaimOutcome::published([]);
        });
        self::assertSame([$undone], $claimed);
    }

    public function testFailingGivesTheCreatedAtAsTheNextAttemptOfARowWithNoAvailableAt(): void
    {
        $store = new InMemoryOutboxStore();
        $row = new OutboxRecord(self::ID, 'default', 'order.placed', '{"a":1}', [], 1_000, attempts: 2);
        $store->insert($row);

        self::assertEquals(
            [new FailingRow(self::ID, 'default', 'order.placed', 2, 1_000, 1_000, 7)],
            $store->failing('default', 1, 10),
        );
    }

    public function testRowOfAnUnknownIdThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown row 0190a1b2-0000-7000-8000-00000000ffff');

        (new InMemoryOutboxStore())->row('0190a1b2-0000-7000-8000-00000000ffff');
    }

    private static function record(string $id, int $ageMs): OutboxRecord
    {
        return new OutboxRecord($id, 'default', 'order.placed', '{}', [], EpochMillis::now() - $ageMs);
    }
}
