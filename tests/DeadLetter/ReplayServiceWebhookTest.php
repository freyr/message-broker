<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\DeadLetter;

use Freyr\MessageBroker\DeadLetter\AlreadyReplayed;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\DeadLetter\NotReplayable;
use Freyr\MessageBroker\DeadLetter\ReplayDestination;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\Transaction;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use Freyr\MessageBroker\Testing\InMemoryTransaction;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\RecordingTransaction;
use Freyr\MessageBroker\Tests\Fixtures\DeadLetterFactory;
use Freyr\MessageBroker\Tests\Fixtures\FailingDeliveryStore;
use Freyr\MessageBroker\Tests\Fixtures\RecordingDeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The replay of a dead letter whose source starts with "webhook:": a new
 * delivery row for the lane of the source, in place of an outbox row.
 */
final class ReplayServiceWebhookTest extends TestCase
{
    private const string RAW_BODY = "\x00not a canonical document {";

    private InMemoryTransaction $transaction;
    private InMemoryDeadLetterStore $store;
    private InMemoryOutboxStore $outbox;
    private InMemoryDeliveryStore $deliveries;
    private RecordingEvents $events;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-06-15 12:00:00.250 UTC'));
        $this->transaction = new InMemoryTransaction();
        $this->store = new InMemoryDeadLetterStore($this->transaction);
        $this->outbox = new InMemoryOutboxStore($this->transaction);
        $this->deliveries = new InMemoryDeliveryStore(transaction: $this->transaction);
        $this->events = new RecordingEvents();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testAWebhookDeadLetterGetsOneNewDeliveryRowForTheLaneOfItsSourceAndNoOutboxRow(): void
    {
        $this->store->add(DeadLetterFactory::make(
            'dl-1',
            messageName: 'order.placed',
            source: 'webhook:crm',
            body: self::RAW_BODY,
            messageId: '01900000-0000-7000-9000-000000000042',
        ));

        $this->service()->replay('dl-1');

        $rows = $this->deliveries->records();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('01900000-0000-7000-9000-000000000042', $row->messageId);
        self::assertSame('crm', $row->lane);
        self::assertSame('order.placed', $row->messageName);
        self::assertSame(self::RAW_BODY, $row->body, 'the body bytes go to the row with no decode');
        self::assertSame(0, $row->attempts);
        self::assertNull($row->availableAt, 'the row is due at once');
        self::assertSame(EpochMillis::now(), $row->createdAt);
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($row->id));
        self::assertNotSame($row->messageId, $row->id, 'the row has its own id');
        self::assertSame([], $this->outbox->unpublishedIds());
        self::assertNotNull($this->store->find('dl-1')?->replayedAt);
        self::assertFalse($this->transaction->isActive(), 'the transaction committed');
    }

    public function testTheLaneArgumentHasNoEffectOnAWebhookDeadLetter(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:erp'));

        $this->service()->replay('dl-1', 'orders');

        self::assertSame(['erp'], array_map(static fn (DeliveryRecord $r): string => $r->lane, $this->deliveries->records()));
        self::assertSame([], $this->outbox->unpublishedIds());
    }

    public function testTheRowHasTheHeadersOfTheDeadLetterWithoutTheStripListAndWithNoReplayTarget(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:crm', headers: [
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
            'x-last-death-reason' => 'rejected',
            'x-dead-letter' => '{"stale":true}',
            'x-deathly' => 'kept',
        ]));

        $this->service()->replay('dl-1');

        self::assertSame([
            'correlation_id' => 'c-1',
            'x-deathly' => 'kept',
        ], $this->deliveries->records()[0]->headers);
    }

    public function testAWebhookReplayRecordsTheReplayedEventWithTheLaneOfTheSource(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', messageName: 'order.placed', source: 'webhook:crm', messageId: 'm-42'));

        $this->service()->replay('dl-1', 'orders');

        self::assertSame([[
            'event' => BrokerEvents::REPLAYED,
            'context' => [
                'message_id' => 'm-42',
                'message_name' => 'order.placed',
                'lane' => 'crm',
                'target' => 'webhook:crm',
            ],
        ]], $this->events->records);
    }

    public function testASecondReplayIsAlreadyReplayedAndInsertsNoRow(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:crm'));
        $service = $this->service();
        $service->replay('dl-1');

        try {
            $service->replay('dl-1');
            self::fail('Expected AlreadyReplayed');
        } catch (AlreadyReplayed $error) {
            self::assertSame("Dead letter 'dl-1' is already replayed", $error->getMessage());
        }

        self::assertCount(1, $this->deliveries->records());
        self::assertCount(1, $this->events->records);
    }

    public function testAMarkThatAnotherProcessSetFirstRollsBackAndInsertsNoRow(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:crm'));
        $store = new RecordingDeadLetterStore($this->store);
        $store->markReplayedResult = false;
        $transaction = new RecordingTransaction();

        try {
            $this->service($store, transaction: $transaction)->replay('dl-1');
            self::fail('Expected AlreadyReplayed');
        } catch (AlreadyReplayed $error) {
            self::assertSame("Dead letter 'dl-1' is already replayed", $error->getMessage());
        }

        self::assertSame(['begin', 'rollback'], $transaction->calls);
        self::assertSame([], $this->deliveries->records());
        self::assertSame([], $this->events->records);
    }

    public function testAFailedDeliveryInsertRollsTheReplayMarkBackAndPropagatesTheSameThrowable(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:crm'));
        $deliveries = new FailingDeliveryStore($this->deliveries);
        $deliveries->insertError = new RuntimeException('insert failed');

        try {
            $this->service(deliveries: $deliveries)->replay('dl-1');
            self::fail('Expected the insert error');
        } catch (RuntimeException $error) {
            self::assertSame($deliveries->insertError, $error);
        }

        self::assertNull($this->store->find('dl-1')?->replayedAt, 'the mark rolled back');
        self::assertSame([], $this->deliveries->records());
        self::assertFalse($this->transaction->isActive());
        self::assertSame([], $this->events->records);
    }

    public function testTheMarkIsTheFirstWriteAndTheDeliveryInsertTheSecond(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:crm'));
        $transaction = new RecordingTransaction();
        $log = static function (string $call) use ($transaction): void {
            $transaction->calls[] = $call;
        };
        $deliveries = new FailingDeliveryStore($this->deliveries);
        $deliveries->log = $log;

        $this->service(new RecordingDeadLetterStore($this->store, $log), $transaction, $deliveries)->replay('dl-1');

        self::assertSame(['begin', 'markReplayed', 'insert', 'commit'], $transaction->calls);
    }

    public function testAServiceWithNoDeliveryStoreRefusesAWebhookDeadLetterAndStartsNoTransaction(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:crm'));
        $transaction = new RecordingTransaction();
        $service = new ReplayService($this->store, $this->outbox, $transaction, new JsonWireFormat(), $this->events);

        try {
            $service->replay('dl-1');
            self::fail('Expected NotReplayable');
        } catch (NotReplayable $error) {
            self::assertSame(
                "Dead letter 'dl-1' is not replayable: the replay service has no delivery store for a webhook dead letter",
                $error->getMessage(),
            );
        }

        self::assertSame([], $transaction->calls);
        self::assertSame([], $this->outbox->unpublishedIds(), 'a webhook dead letter never goes to the outbox');
        self::assertNull($this->store->find('dl-1')?->replayedAt);
    }

    public function testCheckWithNoDeliveryStoreRefusesAWebhookDeadLetter(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:crm'));
        $service = new ReplayService($this->store, $this->outbox, $this->transaction, new JsonWireFormat());

        $this->expectException(NotReplayable::class);
        $this->expectExceptionMessage('the replay service has no delivery store for a webhook dead letter');

        $service->check('dl-1');
    }

    public function testCheckOfAWebhookDeadLetterWithABodyThatIsNotACanonicalDocumentPassesAndChangesNothing(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:crm', body: self::RAW_BODY));
        $transaction = new RecordingTransaction();

        $this->service(transaction: $transaction)->check('dl-1');

        self::assertSame([], $transaction->calls);
        self::assertSame([], $this->deliveries->records());
        self::assertNull($this->store->find('dl-1')?->replayedAt);
        self::assertSame([], $this->events->records);
    }

    /** @return iterable<string, array{string, string}> */
    public static function sourcesWithNoValidLane(): iterable
    {
        yield 'no lane' => ['webhook:', 'the source has no valid lane: Delivery lane must be 1 to 64 characters, got 0: ""'];
        yield 'lane too long' => [
            'webhook:'.str_repeat('a', 65),
            'the source has no valid lane: Delivery lane must be 1 to 64 characters, got 65: "'.str_repeat('a', 65).'"',
        ];
    }

    #[DataProvider('sourcesWithNoValidLane')]
    public function testASourceWithNoValidLaneIsNotReplayableInReplayAndInCheck(string $source, string $reason): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: $source));
        $transaction = new RecordingTransaction();
        $service = $this->service(transaction: $transaction);

        foreach (['replay', 'check'] as $method) {
            try {
                $service->{$method}('dl-1');
                self::fail("Expected NotReplayable from {$method}()");
            } catch (NotReplayable $error) {
                self::assertSame("Dead letter 'dl-1' is not replayable: {$reason}", $error->getMessage());
            }
        }

        self::assertSame([], $transaction->calls);
        self::assertSame([], $this->deliveries->records());
    }

    public function testAWebhookLaneOfSixtyFourCharactersIsReplayed(): void
    {
        $lane = str_repeat('a', 64);
        $this->store->add(DeadLetterFactory::make('dl-1', source: "webhook:{$lane}"));

        $this->service()->replay('dl-1');

        self::assertSame($lane, $this->deliveries->records()[0]->lane);
    }

    public function testAReplayedOrNotReplayableWebhookDeadLetterIsRefusedBeforeTheTransaction(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-replayed', source: 'webhook:crm', replayedAt: 1_750_000_000_200));
        $this->store->add(DeadLetterFactory::make('dl-marked', source: 'webhook:crm', replayable: false));
        $transaction = new RecordingTransaction();
        $service = $this->service(transaction: $transaction);

        try {
            $service->replay('dl-replayed');
            self::fail('Expected AlreadyReplayed');
        } catch (AlreadyReplayed) {
        }
        try {
            $service->replay('dl-marked');
            self::fail('Expected NotReplayable');
        } catch (NotReplayable $error) {
            self::assertSame("Dead letter 'dl-marked' is not replayable: marked not replayable", $error->getMessage());
        }

        self::assertSame([], $transaction->calls);
        self::assertSame([], $this->deliveries->records());
    }

    /** @return iterable<string, array{string}> */
    public static function consumerSources(): iterable
    {
        yield 'queue' => ['orders_q'];
        yield 'prefix not at the start' => ['my-webhook:crm'];
        yield 'other case' => ['Webhook:crm'];
    }

    #[DataProvider('consumerSources')]
    public function testADeadLetterWithAnotherSourceKeepsTheReplayToTheOutboxWithAServiceThatHasADeliveryStore(string $source): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: $source, messageId: 'm-42'));

        $this->service()->replay('dl-1', 'orders');

        self::assertSame(['m-42'], $this->outbox->unpublishedIds());
        self::assertSame('orders', $this->outbox->row('m-42')->lane);
        self::assertSame([
            'x-replay-target' => $source,
        ], $this->outbox->row('m-42')->headers);
        self::assertSame([], $this->deliveries->records());
    }

    public function testReplayAndCheckOfAWebhookDeadLetterReturnTheLaneOfTheSourceAsAWebhookDestination(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'webhook:crm'));
        $service = $this->service();

        self::assertEquals(new ReplayDestination('crm', webhook: true), $service->check('dl-1', 'orders'));
        self::assertEquals(new ReplayDestination('crm', webhook: true), $service->replay('dl-1', 'orders'));
    }

    public function testReplayAndCheckOfAConsumerDeadLetterReturnTheLaneArgumentAsAnOutboxDestination(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'orders_q'));
        $service = $this->service();

        self::assertEquals(new ReplayDestination('orders', webhook: false), $service->check('dl-1', 'orders'));
        self::assertEquals(new ReplayDestination('default', webhook: false), $service->check('dl-1'));
        self::assertEquals(new ReplayDestination('orders', webhook: false), $service->replay('dl-1', 'orders'));
    }

    private function service(
        ?DeadLetterStore $store = null,
        ?Transaction $transaction = null,
        ?DeliveryStore $deliveries = null,
    ): ReplayService {
        return new ReplayService(
            $store ?? $this->store,
            $this->outbox,
            $transaction ?? $this->transaction,
            new JsonWireFormat(),
            $this->events,
            $deliveries ?? $this->deliveries,
        );
    }
}
