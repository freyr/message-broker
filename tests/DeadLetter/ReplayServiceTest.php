<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\DeadLetter;

use Freyr\MessageBroker\DeadLetter\AlreadyReplayed;
use Freyr\MessageBroker\DeadLetter\DeadLetterNotFound;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\DeadLetter\NotReplayable;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\Transaction;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use Freyr\MessageBroker\Testing\InMemoryTransaction;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\RecordingTransaction;
use Freyr\MessageBroker\Tests\Fixtures\DeadLetterFactory;
use Freyr\MessageBroker\Tests\Fixtures\RecordingDeadLetterStore;
use Freyr\MessageBroker\Tests\Fixtures\RecordingOutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Throwable;

final class ReplayServiceTest extends TestCase
{
    private InMemoryTransaction $transaction;
    private InMemoryDeadLetterStore $store;
    private InMemoryOutboxStore $outbox;
    private RecordingEvents $events;

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    protected function setUp(): void
    {
        $this->transaction = new InMemoryTransaction();
        $this->store = new InMemoryDeadLetterStore($this->transaction);
        $this->outbox = new InMemoryOutboxStore($this->transaction);
        $this->events = new RecordingEvents();
    }

    public function testAReplayInsertsOneOutboxRowAndSetsTheMarkInOneCommittedTransaction(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', messageId: 'm-42', messageName: 'order.placed'));

        $this->service()->replay('dl-1', 'orders');

        self::assertSame(['m-42'], $this->outbox->unpublishedIds());
        $row = $this->outbox->row('m-42');
        self::assertSame('orders', $row->lane);
        self::assertSame('order.placed', $row->messageName);
        self::assertSame('{"order_id":"o-1"}', $row->body);
        self::assertNotNull($this->store->find('dl-1')?->replayedAt);
        self::assertFalse($this->transaction->isActive(), 'the transaction committed');
    }

    public function testReplayRecordsAnEventWithMessageIdNameAndLane(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', messageId: 'm-42', messageName: 'order.placed'));

        $this->service()->replay('dl-1', 'orders');

        self::assertSame([[
            'event' => BrokerEvents::REPLAYED,
            'context' => [
                'message_id' => 'm-42',
                'message_name' => 'order.placed',
                'lane' => 'orders',
                'target' => 'orders_q',
            ],
        ]], $this->events->records);
    }

    public function testTheOutboxRowHasTheReplayTargetAndNoneOfTheStripListHeaders(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'payments_q', messageId: 'm-42', headers: [
            'correlation_id' => 'c-1',
            'x-attempt' => 3,
            'x-message-id' => 'stale-id',
            'x-message-name' => 'stale.name',
            'x-created-at' => 1,
            'x-replay-target' => 'old_q',
            'x-delivery-count' => 7,
            'x-death' => [[
                'queue' => 'old_q',
            ]],
            'x-first-death-queue' => 'old_q',
            'x-first-death-reason' => 'rejected',
            'x-last-death-queue' => 'old_q',
            'x-dead-letter' => '{"stale":true}',
            'x-deathly' => 'kept: only the exact name x-death is stripped',
            'trace-x-first-death-' => 'kept: the prefix must start the name',
        ]));

        $this->service()->replay('dl-1');

        self::assertSame([
            'correlation_id' => 'c-1',
            'x-deathly' => 'kept: only the exact name x-death is stripped',
            'trace-x-first-death-' => 'kept: the prefix must start the name',
            'x-replay-target' => 'payments_q',
        ], $this->outbox->row('m-42')->headers);
        self::assertSame(7, $this->store->find('dl-1')?->headers['x-delivery-count'], 'the dead letter keeps its headers');
    }

    public function testTwoReplaysOfOneMessageIdInOneMillisecondGetDifferentCreatedAt(): void
    {
        $clock = new MockClock('2026-06-15 12:00:00.250 UTC');
        Clock::set($clock);
        $now = EpochMillis::now();
        $this->store->add(DeadLetterFactory::make('dl-a', source: 'orders_q', messageId: 'm-1'));
        $this->store->add(DeadLetterFactory::make('dl-b', source: 'billing_q', messageId: 'm-1'));
        $this->store->add(DeadLetterFactory::make('dl-c', source: 'audit_q', messageId: 'm-1'));
        $outbox = new RecordingOutboxStore();
        $service = $this->service(outbox: $outbox);

        $service->replay('dl-a');
        $service->replay('dl-b');
        $clock->sleep(1);
        $service->replay('dl-c');

        self::assertSame(
            [$now, $now + 1, $now + 1000],
            array_map(static fn (OutboxRecord $r): int => $r->createdAt, $outbox->inserted),
            'the later of now and the last value + 1 ms',
        );
    }

    public function testAReplayedRowIsAlreadyReplayedAndNoTransactionStarts(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', replayedAt: 1_750_000_000_200));
        $transaction = new RecordingTransaction();
        $outbox = new RecordingOutboxStore();

        try {
            $this->service(outbox: $outbox, transaction: $transaction)->replay('dl-1');
            self::fail('Expected AlreadyReplayed');
        } catch (AlreadyReplayed $error) {
            self::assertSame("Dead letter 'dl-1' is already replayed", $error->getMessage());
        }

        self::assertSame([], $transaction->calls);
        self::assertSame([], $outbox->inserted);
        self::assertSame(1_750_000_000_200, $this->store->find('dl-1')?->replayedAt);
        self::assertSame([], $this->events->records);
    }

    public function testAMarkThatAnotherProcessSetFirstRollsBackAndIsAlreadyReplayed(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $store = new RecordingDeadLetterStore($this->store);
        $store->markReplayedResult = false;
        $transaction = new RecordingTransaction();
        $outbox = new RecordingOutboxStore();

        try {
            $this->service($store, $outbox, $transaction)->replay('dl-1');
            self::fail('Expected AlreadyReplayed');
        } catch (AlreadyReplayed $error) {
            self::assertSame("Dead letter 'dl-1' is already replayed", $error->getMessage());
        }

        self::assertSame(['begin', 'rollback'], $transaction->calls);
        self::assertSame([], $outbox->inserted);
        self::assertSame([], $this->events->records);
    }

    public function testARowMarkedNotReplayableIsNotReplayableAndNoTransactionStarts(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', replayable: false));
        $transaction = new RecordingTransaction();

        try {
            $this->service(transaction: $transaction)->replay('dl-1');
            self::fail('Expected NotReplayable');
        } catch (NotReplayable $error) {
            self::assertSame("Dead letter 'dl-1' is not replayable: marked not replayable", $error->getMessage());
        }

        self::assertSame([], $transaction->calls);
        self::assertSame([], $this->outbox->unpublishedIds());
        self::assertNull($this->store->find('dl-1')?->replayedAt);
    }

    public function testUnknownDeadLetterIsNotFound(): void
    {
        $transaction = new RecordingTransaction();

        try {
            $this->service(transaction: $transaction)->replay('nope');
            self::fail('Expected DeadLetterNotFound');
        } catch (DeadLetterNotFound $error) {
            self::assertSame("Dead letter 'nope' not found", $error->getMessage());
        }

        self::assertSame([], $transaction->calls);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unreplayableBodies(): iterable
    {
        yield 'not json' => ['raw bytes', 'body is not a replayable wire document'];
        yield 'json scalar' => ['123', 'body is not a replayable wire document'];
        yield 'metadata missing' => ['{"payload":{"a":1}}', 'body is not a replayable wire document'];
        yield 'payload missing' => ['{"metadata":{"message_name":"x"}}', 'body is not a replayable wire document'];
        yield 'payload is not an object' => [
            '{"metadata":{"message_name":"x"},"payload":"text"}',
            'document has non-object metadata/payload',
        ];
        yield 'metadata is not an object' => [
            '{"metadata":"text","payload":{"a":1}}',
            'document has non-object metadata/payload',
        ];
        yield 'message name is not a string' => [
            '{"metadata":{"message_name":5},"payload":{"a":1}}',
            'metadata has no string message_name',
        ];
    }

    public function testRawBytesBodyExplainsWhyItIsNotReplayable(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', body: 'raw bytes'));

        $this->expectException(NotReplayable::class);
        $this->expectExceptionMessage(
            "Dead letter 'dl-1' is not replayable: body is not a replayable wire document "
            .'(stage-1 dead letters store raw bytes and are not replayable)',
        );

        $this->service()->replay('dl-1');
    }

    #[DataProvider('unreplayableBodies')]
    public function testUnreplayableBodyIsNotReplayableAndNothingIsEnqueued(string $body, string $expectedMessage): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', body: $body));
        $transaction = new RecordingTransaction();

        try {
            $this->service(transaction: $transaction)->replay('dl-1');
            self::fail('Expected NotReplayable');
        } catch (NotReplayable $error) {
            self::assertStringStartsWith("Dead letter 'dl-1' is not replayable: {$expectedMessage}", $error->getMessage());
        }

        self::assertSame([], $transaction->calls);
        self::assertSame([], $this->outbox->unpublishedIds());
        self::assertSame([], $this->events->records);
        self::assertNull($this->store->find('dl-1')?->replayedAt);
    }

    public function testAnInsertThatThrowsRollsBackTheMarkAndPropagatesTheSameThrowable(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $outbox = new RecordingOutboxStore();
        $outbox->insertFails = new RuntimeException('insert failed');

        try {
            $this->service(outbox: $outbox)->replay('dl-1');
            self::fail('Expected the insert error');
        } catch (RuntimeException $error) {
            self::assertSame($outbox->insertFails, $error);
        }

        self::assertNull($this->store->find('dl-1')?->replayedAt, 'the mark rolled back');
        self::assertFalse($this->transaction->isActive());
        self::assertSame([], $this->events->records);
    }

    public function testARollbackThatThrowsAfterAnInsertFailureGivesTheInsertError(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $outbox = new RecordingOutboxStore();
        $outbox->insertFails = new RuntimeException('insert failed');
        $transaction = new RecordingTransaction();
        $transaction->rollBackFails = new RuntimeException('connection gone');

        try {
            $this->service(outbox: $outbox, transaction: $transaction)->replay('dl-1');
            self::fail('Expected the insert error');
        } catch (RuntimeException $error) {
            self::assertSame($outbox->insertFails, $error);
        }

        self::assertSame(['begin', 'rollback'], $transaction->calls);
    }

    public function testTheMarkIsTheFirstWriteInTheTransaction(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $transaction = new RecordingTransaction();
        $log = static function (string $call) use ($transaction): void {
            $transaction->calls[] = $call;
        };

        $this->service(
            new RecordingDeadLetterStore($this->store, $log),
            new RecordingOutboxStore($log),
            $transaction,
        )->replay('dl-1');

        self::assertSame(['begin', 'markReplayed', 'insert', 'commit'], $transaction->calls);
    }

    public function testACommitFailureIsReportedAndASecondReplayOfACommittedRowIsAlreadyReplayed(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $outbox = new RecordingOutboxStore();
        $commitError = new RuntimeException('connection lost at commit');
        $transaction = new class($this->transaction, $commitError) implements Transaction {
            public function __construct(
                private readonly Transaction $inner,
                private readonly Throwable $commitError,
            ) {}

            public function begin(): void
            {
                $this->inner->begin();
            }

            public function commit(): void
            {
                $this->inner->commit();

                throw $this->commitError;
            }

            public function rollBack(): void
            {
                $this->inner->rollBack();
            }
        };
        $service = $this->service(outbox: $outbox, transaction: $transaction);

        try {
            $service->replay('dl-1');
            self::fail('Expected the commit error');
        } catch (RuntimeException $error) {
            self::assertSame($commitError, $error, 'the replay reports a failure');
        }
        self::assertSame([], $this->events->records);

        $this->expectException(AlreadyReplayed::class);
        try {
            $service->replay('dl-1');
        } finally {
            self::assertCount(1, $outbox->inserted, 'the second replay inserts nothing');
        }
    }

    public function testCheckOfAReplayableRowChangesNothing(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $transaction = new RecordingTransaction();

        $this->service(transaction: $transaction)->check('dl-1');

        self::assertSame([], $transaction->calls);
        self::assertSame([], $this->outbox->unpublishedIds());
        self::assertNull($this->store->find('dl-1')?->replayedAt);
        self::assertSame([], $this->events->records);
    }

    /** @return iterable<string, array{string, class-string<Throwable>, string}> */
    public static function refusals(): iterable
    {
        yield 'unknown' => ['nope', DeadLetterNotFound::class, "Dead letter 'nope' not found"];
        yield 'replayed' => ['dl-replayed', AlreadyReplayed::class, "Dead letter 'dl-replayed' is already replayed"];
        yield 'marked not replayable' => [
            'dl-marked',
            NotReplayable::class,
            "Dead letter 'dl-marked' is not replayable: marked not replayable",
        ];
        yield 'raw body' => [
            'dl-raw',
            NotReplayable::class,
            "Dead letter 'dl-raw' is not replayable: metadata has no string message_name",
        ];
    }

    /** @param class-string<Throwable> $class */
    #[DataProvider('refusals')]
    public function testCheckRefusesWhatReplayRefusesAndChangesNothing(string $id, string $class, string $message): void
    {
        $this->store->add(DeadLetterFactory::make('dl-replayed', replayedAt: 1_750_000_000_200));
        $this->store->add(DeadLetterFactory::make('dl-marked', replayable: false));
        $this->store->add(DeadLetterFactory::make('dl-raw', body: '{"metadata":{},"payload":{}}'));
        $transaction = new RecordingTransaction();

        try {
            $this->service(transaction: $transaction)->check($id);
            self::fail("Expected {$class}");
        } catch (Throwable $error) {
            self::assertSame($class, $error::class);
            self::assertSame($message, $error->getMessage());
        }

        self::assertSame([], $transaction->calls);
        self::assertSame([], $this->outbox->unpublishedIds());
        self::assertNull($this->store->find('dl-marked')?->replayedAt);
        self::assertNull($this->store->find('dl-raw')?->replayedAt);
        self::assertSame(1_750_000_000_200, $this->store->find('dl-replayed')?->replayedAt);
    }

    private function service(
        ?DeadLetterStore $store = null,
        ?OutboxStore $outbox = null,
        ?Transaction $transaction = null,
    ): ReplayService {
        return new ReplayService(
            $store ?? $this->store,
            $outbox ?? $this->outbox,
            $transaction ?? $this->transaction,
            new JsonWireFormat(),
            $this->events,
        );
    }
}
