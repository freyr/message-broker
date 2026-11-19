<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Storage\Transaction;
use Freyr\MessageBroker\Time\EpochMillis;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contract tests of the {@see Transaction} port: the work of a transaction
 * commits or rolls back as one. The rule behind the exactly-once guarantee is
 * that the deduplication row rolls back with the transaction of the delivery.
 *
 * An adapter extends this class and gives the transaction and the stores. They
 * must work on the SAME connection (or the same unit of work), and all of them
 * must be EMPTY at the start of each test:
 *  - createTransaction(): the transaction under test;
 *  - createDeduplicationStore(): a deduplication store on the connection of the
 *    transaction;
 *  - createOutboxStore() (optional hook): an outbox store on that connection.
 *    Without it the tests of the outbox insert are skipped;
 *  - createDeadLetterStore() (optional hook): a dead letter store on that
 *    connection. Without it the tests of the replay mark are skipped. The test
 *    of a mark and an outbox insert in one transaction needs both hooks.
 *
 * The test case calls each factory once per test, in this order and before the
 * first begin(): transaction, deduplication store, outbox store, dead letter
 * store. A store can therefore join the transaction in its constructor.
 */
abstract class TransactionContractTestCase extends TestCase
{
    private ?Transaction $transaction = null;
    private ?DeduplicationStore $deduplication = null;
    private ?OutboxStore $outbox = null;
    private ?DeadLetterStore $deadLetters = null;
    private bool $created = false;

    abstract protected function createTransaction(): Transaction;

    /** A deduplication store on the connection of the transaction. */
    abstract protected function createDeduplicationStore(): DeduplicationStore;

    /** An outbox store on the connection of the transaction. Return null when the adapter has none. */
    protected function createOutboxStore(): ?OutboxStore
    {
        return null;
    }

    /** A dead letter store on the connection of the transaction. Return null when the adapter has none. */
    protected function createDeadLetterStore(): ?DeadLetterStore
    {
        return null;
    }

    public function testTheWorkOfACommittedTransactionStays(): void
    {
        $message = self::incoming('m-1');

        $this->transaction()->begin();
        self::assertTrue($this->deduplication()->acquire($message, 'orders_consumer'));
        $this->transaction()->commit();

        self::assertFalse($this->deduplication()->acquire($message, 'orders_consumer'), 'the committed entry stays');
    }

    public function testTheDeduplicationRowRollsBackWithTheTransaction(): void
    {
        $message = self::incoming('m-1');

        $this->transaction()->begin();
        self::assertTrue($this->deduplication()->acquire($message, 'orders_consumer'));
        $this->transaction()->rollBack();

        self::assertTrue(
            $this->deduplication()->acquire($message, 'orders_consumer'),
            'a rolled-back delivery leaves no entry: the redelivery is processed',
        );
    }

    public function testARollBackUndoesOnlyTheWorkOfItsOwnTransaction(): void
    {
        $this->transaction()->begin();
        $this->deduplication()->acquire(self::incoming('m-kept'), 'orders_consumer');
        $this->transaction()->commit();

        $this->transaction()->begin();
        $this->deduplication()->acquire(self::incoming('m-undone'), 'orders_consumer');
        $this->transaction()->rollBack();

        self::assertFalse($this->deduplication()->acquire(self::incoming('m-kept'), 'orders_consumer'), 'the commit stays');
        self::assertTrue($this->deduplication()->acquire(self::incoming('m-undone'), 'orders_consumer'), 'the rollback is complete');
    }

    public function testATransactionCanStartAfterTheEndOfThePreviousOne(): void
    {
        $this->transaction()->begin();
        $this->transaction()->rollBack();
        $this->transaction()->begin();
        $this->deduplication()->acquire(self::incoming('m-1'), 'orders_consumer');
        $this->transaction()->commit();
        $this->transaction()->begin();
        $this->transaction()->commit();

        self::assertFalse($this->deduplication()->acquire(self::incoming('m-1'), 'orders_consumer'));
    }

    public function testAnOutboxRowInsertedInACommittedTransactionIsClaimable(): void
    {
        $outbox = $this->outboxOrSkip();
        $record = self::outboxRecord();

        $this->transaction()->begin();
        $outbox->insert($record);
        $this->transaction()->commit();

        self::assertSame([$record->id], self::claimIds($outbox));
    }

    public function testAnOutboxRowInsertedInARolledBackTransactionIsGone(): void
    {
        $outbox = $this->outboxOrSkip();

        $this->transaction()->begin();
        $outbox->insert(self::outboxRecord());
        $this->transaction()->rollBack();

        self::assertSame([], self::claimIds($outbox), 'the row rolls back with the state change of the application');
    }

    public function testAReplayMarkInARolledBackTransactionIsGone(): void
    {
        $deadLetters = $this->deadLettersOrSkip();
        $deadLetter = self::deadLetter();
        $deadLetters->store($deadLetter);

        $this->transaction()->begin();
        $deadLetters->markReplayed($deadLetter->id);
        $this->transaction()->rollBack();

        $found = $deadLetters->find($deadLetter->id);
        self::assertNotNull($found, 'the row stored before the transaction stays');
        self::assertNull($found->replayedAt, 'the mark rolls back with the transaction');
        self::assertTrue($deadLetters->markReplayed($deadLetter->id), 'a new mark after the rollback is set');
    }

    public function testAReplayMarkAndAnOutboxInsertInACommittedTransactionBothStay(): void
    {
        $outbox = $this->outboxOrSkip();
        $deadLetters = $this->deadLettersOrSkip();
        $deadLetter = self::deadLetter();
        $deadLetters->store($deadLetter);
        $record = self::outboxRecord();

        $this->transaction()->begin();
        $deadLetters->markReplayed($deadLetter->id);
        $outbox->insert($record);
        $this->transaction()->commit();

        self::assertNotNull($deadLetters->find($deadLetter->id)?->replayedAt, 'the mark stays');
        self::assertSame([$record->id], self::claimIds($outbox), 'the outbox row stays');
    }

    final protected function transaction(): Transaction
    {
        $this->create();

        return $this->transaction ?? throw new LogicException('The transaction factory returned nothing.');
    }

    final protected function deduplication(): DeduplicationStore
    {
        $this->create();

        return $this->deduplication ?? throw new LogicException('The deduplication factory returned nothing.');
    }

    private function create(): void
    {
        if ($this->created) {
            return;
        }
        $this->created = true;
        $this->transaction = $this->createTransaction();
        $this->deduplication = $this->createDeduplicationStore();
        $this->outbox = $this->createOutboxStore();
        $this->deadLetters = $this->createDeadLetterStore();
    }

    private function outboxOrSkip(): OutboxStore
    {
        $this->create();
        $outbox = $this->outbox;
        if ($outbox === null) {
            self::markTestSkipped('The adapter gives no outbox store: override createOutboxStore() to run the tests of the outbox insert.');
        }

        return $outbox;
    }

    private function deadLettersOrSkip(): DeadLetterStore
    {
        $this->create();
        $deadLetters = $this->deadLetters;
        if ($deadLetters === null) {
            self::markTestSkipped('The adapter gives no dead letter store: override createDeadLetterStore() to run the tests of the replay mark.');
        }

        return $deadLetters;
    }

    /** @return list<string> */
    private static function claimIds(OutboxStore $outbox): array
    {
        $ids = [];
        $outbox->drainClaimed('contract', 10, static function (array $records) use (&$ids): ClaimOutcome {
            $ids = array_map(static fn (OutboxRecord $r): string => $r->id, $records);

            return ClaimOutcome::published($ids);
        });

        return $ids;
    }

    private static function outboxRecord(): OutboxRecord
    {
        return new OutboxRecord(
            id: Uuid::v7()->toString(),
            lane: 'contract',
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: EpochMillis::now() - 1_000,
        );
    }

    private static function deadLetter(): DeadLetter
    {
        return new DeadLetter(
            id: Uuid::v7()->toString(),
            source: 'orders_q',
            messageId: Uuid::v7()->toString(),
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            errorClass: 'RuntimeException',
            errorMessage: 'boom',
            errorTrace: '#0 trace-line',
            attempts: 1,
            failedAt: EpochMillis::now() - 60_000,
        );
    }

    private static function incoming(string $messageId): IncomingMessage
    {
        return new IncomingMessage(
            messageId: $messageId,
            messageName: 'order.placed',
            createdAt: EpochMillis::now(),
            payload: [],
        );
    }
}
