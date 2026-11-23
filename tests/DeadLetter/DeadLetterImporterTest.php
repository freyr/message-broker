<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\DeadLetter;

use Freyr\MessageBroker\BrokerHeader;
use Freyr\MessageBroker\Consumer\Delivery;
use Freyr\MessageBroker\Consumer\DeliveryLimitExceeded;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterBuilder;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterHeader;
use Freyr\MessageBroker\DeadLetter\DeadLetterImporter;
use Freyr\MessageBroker\DeadLetter\MovedByTransport;
use Freyr\MessageBroker\DeadLetter\TransportMove;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * The import of one message of a dead letter queue into the store: case 1 (a
 * valid header x-dead-letter), case 2 (a broker move, the message
 * deserializes), case 3 (a broker move, the message does not deserialize) and
 * case 4 (no broker move).
 */
final class DeadLetterImporterTest extends TestCase
{
    private const string SINK_ID = '0190f7e2-1234-7000-8000-abc123456789';

    private const string WORK_QUEUE = 'orders_q';

    private const string DEAD_LETTER_QUEUE = 'orders_q.dlq';

    private const int NOW = 1_795_219_200_123;

    private const int MOVED_AT = 1_795_219_100_000;

    private InMemoryDeadLetterStore $store;

    private DeadLetterImporter $importer;

    protected function setUp(): void
    {
        Clock::set(new MockClock(EpochMillis::toDateTime(self::NOW)));
        $this->store = new InMemoryDeadLetterStore();
        $this->importer = new DeadLetterImporter($this->store, new JsonDeserializer(), new DeadLetterBuilder());
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    /** @return array<string, mixed> */
    private static function xDeath(string $reason = 'delivery_limit'): array
    {
        return [
            'x-death' => [[
                'queue' => self::WORK_QUEUE,
                'reason' => $reason,
                'count' => 1,
                'exchange' => '',
            ]],
        ];
    }

    /** @param array<string, mixed> $extraHeaders */
    private static function delivery(
        array $extraHeaders = [],
        string $body = '{"order_id":"o-1"}',
        bool $withEnvelope = true,
        int $attempt = 2,
        ?string $fallbackMessageId = null,
    ): Delivery {
        $headers = ($withEnvelope ? MetadataHeader::envelope('m-1', 'order.placed', 1_765_476_000_123) : [])
            + $extraHeaders;

        return new Delivery($body, $headers, $attempt, self::DEAD_LETTER_QUEUE, $fallbackMessageId, 9);
    }

    private static function move(string $reason = 'delivery_limit', ?int $movedAt = self::MOVED_AT): TransportMove
    {
        return new TransportMove(self::WORK_QUEUE, $reason, 1, $movedAt);
    }

    /** The message of the deserializer error for the delivery: its text depends on the PHP version. */
    private static function deserializerError(Delivery $delivery): string
    {
        try {
            (new JsonDeserializer())->deserialize($delivery->body, $delivery->headers);
        } catch (MalformedMessage $error) {
            self::assertStringStartsWith('Body is not valid JSON: ', $error->getMessage());

            return $error->getMessage();
        }

        self::fail('the delivery must not deserialize');
    }

    /** @return list<DeadLetter> */
    private function rows(): array
    {
        return $this->store->list(new DeadLetterFilter());
    }

    // case 2: a broker move, the message deserializes

    public function testABrokerMoveAtTheDeliveryLimitGivesAReplayableCanonicalRowOfTheWorkQueue(): void
    {
        $delivery = self::delivery(self::xDeath());

        $returned = $this->importer->import($delivery, self::move());

        self::assertCount(1, $this->rows());
        $row = $this->rows()[0];
        self::assertSame($returned, $row, 'import() returns the dead letter that it stored');
        self::assertTrue(Uuid::isValid($row->id));
        self::assertSame(self::WORK_QUEUE, $row->source);
        self::assertSame('m-1', $row->messageId);
        self::assertSame('order.placed', $row->messageName);
        self::assertSame(
            '{"metadata":{"message_name":"order.placed","message_id":"m-1","created_at":1765476000123},"payload":{"order_id":"o-1"}}',
            $row->body,
        );
        self::assertSame($delivery->headers, $row->headers, 'all headers of the message, also x-death');
        self::assertSame(self::xDeath()['x-death'], $row->headers['x-death']);
        self::assertSame(DeliveryLimitExceeded::class, $row->errorClass);
        self::assertSame("Moved by the transport: reason 'delivery_limit', queue 'orders_q', count 1", $row->errorMessage);
        self::assertSame('', $row->errorTrace);
        self::assertSame(2, $row->attempts, 'the attempt of the delivery, never the delivery count');
        self::assertSame(self::MOVED_AT, $row->failedAt);
        self::assertNull($row->replayedAt);
        self::assertTrue($row->replayable);
    }

    public function testABrokerMoveForAnotherReasonGivesMovedByTransport(): void
    {
        $this->importer->import(self::delivery(self::xDeath('rejected')), self::move('rejected'));

        $row = $this->rows()[0];
        self::assertSame(MovedByTransport::class, $row->errorClass);
        self::assertSame("Moved by the transport: reason 'rejected', queue 'orders_q', count 1", $row->errorMessage);
        self::assertSame(self::WORK_QUEUE, $row->source);
        self::assertTrue($row->replayable);
    }

    public function testTheErrorMessageNamesTheCountOfTheMove(): void
    {
        $this->importer->import(self::delivery(), new TransportMove('payments_q', 'maxlen', 17, self::MOVED_AT));

        self::assertSame(
            "Moved by the transport: reason 'maxlen', queue 'payments_q', count 17",
            $this->rows()[0]->errorMessage,
        );
        self::assertSame('payments_q', $this->rows()[0]->source);
    }

    public function testABrokerMoveWithNoTimeUsesTheClock(): void
    {
        $this->importer->import(self::delivery(), self::move(movedAt: null));

        self::assertSame(self::NOW, $this->rows()[0]->failedAt);
    }

    public function testAMoveDefaultsToNoTime(): void
    {
        $move = new TransportMove(self::WORK_QUEUE, 'delivery_limit', 3);

        self::assertNull($move->movedAt);
        self::assertSame(self::WORK_QUEUE, $move->sourceQueue);
        self::assertSame('delivery_limit', $move->reason);
        self::assertSame(3, $move->count);
    }

    // case 3: a broker move, the message does not deserialize

    public function testABrokerMoveOfAMalformedMessageGivesARawRowThatIsNotReplayable(): void
    {
        $delivery = self::delivery(self::xDeath(), body: '{{{not json');

        $this->importer->import($delivery, self::move());

        self::assertCount(1, $this->rows());
        $row = $this->rows()[0];
        self::assertSame(self::WORK_QUEUE, $row->source);
        self::assertSame('m-1', $row->messageId);
        self::assertSame('order.placed', $row->messageName);
        self::assertSame('{{{not json', $row->body, 'the raw bytes');
        self::assertSame($delivery->headers, $row->headers);
        self::assertSame(DeliveryLimitExceeded::class, $row->errorClass);
        self::assertSame(
            "Moved by the transport: reason 'delivery_limit', queue 'orders_q', count 1;"
            .' the body did not deserialize: '.self::deserializerError($delivery),
            $row->errorMessage,
        );
        self::assertSame('', $row->errorTrace);
        self::assertSame(2, $row->attempts);
        self::assertSame(self::MOVED_AT, $row->failedAt);
        self::assertFalse($row->replayable);
    }

    public function testARawRowWithNoEnvelopeTakesTheRawIdentity(): void
    {
        $this->importer->import(
            self::delivery(withEnvelope: false, fallbackMessageId: 'prop-id'),
            self::move('rejected', null),
        );

        $row = $this->rows()[0];
        self::assertSame('prop-id', $row->messageId);
        self::assertSame('unknown', $row->messageName);
        self::assertSame(MovedByTransport::class, $row->errorClass);
        self::assertSame(self::NOW, $row->failedAt);
        self::assertFalse($row->replayable);
    }

    // case 4: no broker move

    public function testAMessageWithNoMoveGivesARowOfTheDeadLetterQueueThatIsNotReplayable(): void
    {
        $delivery = self::delivery([
            'x-custom' => 'h',
        ]);

        $this->importer->import($delivery);

        self::assertCount(1, $this->rows());
        $row = $this->rows()[0];
        self::assertSame(self::DEAD_LETTER_QUEUE, $row->source);
        self::assertSame('m-1', $row->messageId);
        self::assertSame('order.placed', $row->messageName);
        self::assertSame(
            '{"metadata":{"message_name":"order.placed","message_id":"m-1","created_at":1765476000123},"payload":{"order_id":"o-1"}}',
            $row->body,
            'the canonical document when the message deserializes',
        );
        self::assertSame($delivery->headers, $row->headers);
        self::assertSame(MovedByTransport::class, $row->errorClass);
        self::assertSame("Moved by the transport: reason 'unknown', queue 'orders_q.dlq'", $row->errorMessage);
        self::assertSame('', $row->errorTrace);
        self::assertSame(2, $row->attempts);
        self::assertSame(self::NOW, $row->failedAt);
        self::assertFalse($row->replayable, 'never replayable, also when the message deserializes');
    }

    public function testAMalformedMessageWithNoMoveGivesARawRowOfTheDeadLetterQueue(): void
    {
        $delivery = self::delivery(body: 'raw');

        $this->importer->import($delivery);

        $row = $this->rows()[0];
        self::assertSame(self::DEAD_LETTER_QUEUE, $row->source);
        self::assertSame('raw', $row->body);
        self::assertSame(MovedByTransport::class, $row->errorClass);
        self::assertSame(
            "Moved by the transport: reason 'unknown', queue 'orders_q.dlq';"
            .' the body did not deserialize: '.self::deserializerError($delivery),
            $row->errorMessage,
        );
        self::assertSame(self::NOW, $row->failedAt);
        self::assertFalse($row->replayable);
    }

    // a repeat

    public function testASecondImportOfTheSameMessageGivesOneOpenRow(): void
    {
        $this->importer->import(self::delivery(self::xDeath()), self::move());
        $firstId = $this->rows()[0]->id;

        $this->importer->import(self::delivery(self::xDeath(), attempt: 3), self::move());

        self::assertCount(1, $this->rows());
        self::assertSame($firstId, $this->rows()[0]->id, 'the open row keeps its id');
        self::assertSame(3, $this->rows()[0]->attempts, 'the data of the second import');
    }

    // case 1: a valid header x-dead-letter

    private static function sinkDeadLetter(
        string $body = '{"metadata":{"message_id":"m-1"},"payload":{}}',
        bool $replayable = true,
    ): DeadLetter {
        return new DeadLetter(
            id: self::SINK_ID,
            source: self::WORK_QUEUE,
            messageId: 'm-1',
            messageName: 'order.placed',
            body: $body,
            headers: [
                'x-custom' => 'h',
                'x-attempt' => 5,
            ],
            errorClass: 'App\PaymentDeclined',
            errorMessage: 'card expired',
            errorTrace: '#0 trace-line',
            attempts: 5,
            failedAt: 1_795_219_000_456,
            replayable: $replayable,
        );
    }

    /**
     * A message that the sink published: the headers of the dead letter plus x-dead-letter.
     *
     * @param array<string, mixed> $extraHeaders
     */
    private static function sinkDelivery(DeadLetter $deadLetter, array $extraHeaders = []): Delivery
    {
        return new Delivery(
            $deadLetter->body,
            $deadLetter->headers + $extraHeaders + [
                BrokerHeader::DEAD_LETTER => DeadLetterHeader::encode($deadLetter),
            ],
            1,
            self::DEAD_LETTER_QUEUE,
            null,
            0,
        );
    }

    public function testAValidHeaderKeepsTheIdAndEachFieldAndRemovesOnlyTheHeader(): void
    {
        $returned = $this->importer->import(self::sinkDelivery(self::sinkDeadLetter()));

        self::assertCount(1, $this->rows());
        $row = $this->rows()[0];
        self::assertSame($returned, $row, 'import() returns the dead letter that it stored');
        self::assertSame(self::SINK_ID, $row->id);
        self::assertSame(self::WORK_QUEUE, $row->source);
        self::assertSame('m-1', $row->messageId);
        self::assertSame('order.placed', $row->messageName);
        self::assertSame('{"metadata":{"message_id":"m-1"},"payload":{}}', $row->body);
        self::assertSame([
            'x-custom' => 'h',
            'x-attempt' => 5,
        ], $row->headers, 'only x-dead-letter is removed');
        self::assertSame('App\PaymentDeclined', $row->errorClass);
        self::assertSame('card expired', $row->errorMessage);
        self::assertSame('#0 trace-line', $row->errorTrace);
        self::assertSame(5, $row->attempts);
        self::assertSame(1_795_219_000_456, $row->failedAt);
        self::assertNull($row->replayedAt);
        self::assertTrue($row->replayable);
    }

    public function testAValidHeaderKeepsTheRawBytesAndTheMarkThatIsNotReplayable(): void
    {
        $this->importer->import(self::sinkDelivery(self::sinkDeadLetter(body: "raw\xffbytes", replayable: false)));

        $row = $this->rows()[0];
        self::assertSame("raw\xffbytes", $row->body);
        self::assertFalse($row->replayable);
    }

    public function testAValidHeaderHasPriorityOverAMove(): void
    {
        $copiedDeath = [[
            'queue' => 'orders_q.wait.1000',
            'reason' => 'expired',
            'count' => 1,
        ]];

        $this->importer->import(
            self::sinkDelivery(self::sinkDeadLetter(), [
                'x-death' => $copiedDeath,
            ]),
            self::move(),
        );

        $row = $this->rows()[0];
        self::assertSame(self::SINK_ID, $row->id);
        self::assertSame('App\PaymentDeclined', $row->errorClass);
        self::assertSame(1_795_219_000_456, $row->failedAt);
        self::assertSame($copiedDeath, $row->headers['x-death'], 'the other headers stay');
        self::assertArrayNotHasKey(BrokerHeader::DEAD_LETTER, $row->headers);
    }

    /** @return iterable<string, array{mixed}> */
    public static function headersThatDoNotDecode(): iterable
    {
        $valid = json_decode(DeadLetterHeader::encode(self::sinkDeadLetter()), true);
        self::assertIsArray($valid);
        $valid['id'] = 'not-a-uuid';

        yield 'an id that is not a UUID' => [(string) json_encode($valid)];
        yield 'invalid JSON' => ['{"id":'];
        yield 'not a string' => [[
            'id' => self::SINK_ID,
        ]];
    }

    #[DataProvider('headersThatDoNotDecode')]
    public function testAHeaderThatDoesNotDecodeFallsBackToTheMoveWithANewId(mixed $header): void
    {
        $delivery = self::delivery(self::xDeath() + [
            BrokerHeader::DEAD_LETTER => $header,
        ]);

        $this->importer->import($delivery, self::move());

        self::assertCount(1, $this->rows());
        $row = $this->rows()[0];
        self::assertNotSame(self::SINK_ID, $row->id, 'a new id');
        self::assertTrue(Uuid::isValid($row->id));
        self::assertSame(self::WORK_QUEUE, $row->source);
        self::assertSame('m-1', $row->messageId);
        self::assertSame(DeliveryLimitExceeded::class, $row->errorClass);
        self::assertSame(
            "Moved by the transport: reason 'delivery_limit', queue 'orders_q', count 1; x-dead-letter header did not decode",
            $row->errorMessage,
        );
        self::assertSame('', $row->errorTrace);
        self::assertSame(2, $row->attempts);
        self::assertSame(self::MOVED_AT, $row->failedAt);
        self::assertTrue($row->replayable);
        self::assertSame($delivery->headers, $row->headers, 'case 2 keeps all message headers');
    }

    public function testAHeaderThatDoesNotDecodeWithNoMoveGivesCase4WithTheText(): void
    {
        $delivery = self::delivery([
            BrokerHeader::DEAD_LETTER => 'garbage',
        ], body: 'raw');

        $this->importer->import($delivery);

        $row = $this->rows()[0];
        self::assertSame(self::DEAD_LETTER_QUEUE, $row->source);
        self::assertSame(MovedByTransport::class, $row->errorClass);
        self::assertSame(
            "Moved by the transport: reason 'unknown', queue 'orders_q.dlq'; x-dead-letter header did not decode;"
            .' the body did not deserialize: '.self::deserializerError($delivery),
            $row->errorMessage,
        );
        self::assertFalse($row->replayable);
    }

    public function testASecondImportOfACase1MessageWhoseRowIsReplayedChangesNothing(): void
    {
        $deadLetter = self::sinkDeadLetter();
        $this->importer->import(self::sinkDelivery($deadLetter));
        self::assertTrue($this->store->markReplayed(self::SINK_ID));
        $replayed = $this->store->find(self::SINK_ID);
        self::assertNotNull($replayed?->replayedAt);

        $returned = $this->importer->import(self::sinkDelivery($deadLetter));

        self::assertSame(self::SINK_ID, $returned->id);
        self::assertCount(1, $this->store->list(new DeadLetterFilter()), 'no new row');
        self::assertEquals($replayed, $this->store->find(self::SINK_ID), 'the replayed row is unchanged');
    }
}
