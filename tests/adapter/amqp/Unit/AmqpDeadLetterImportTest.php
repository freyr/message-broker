<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Unit;

use DateTime;
use Freyr\MessageBroker\Adapter\Amqp\AmqpDeadLetterImport;
use Freyr\MessageBroker\Consumer\DeliveryLimitExceeded;
use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterBuilder;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterImporter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\DeadLetter\MovedByTransport;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The import of a dead letter queue against a recording channel: basic.get,
 * the TransportMove from x-death, the store, then the ack or the nack.
 */
final class AmqpDeadLetterImportTest extends TestCase
{
    use RecordsChannel;

    private const string DEAD_LETTER_QUEUE = 'orders.dlq';

    private const int TIME = 1_795_219_200;

    private InMemoryDeadLetterStore $store;

    protected function setUp(): void
    {
        $this->setUpChannel();
        $this->store = new InMemoryDeadLetterStore();
    }

    private function import(?DeadLetterStore $store = null): AmqpDeadLetterImport
    {
        return new AmqpDeadLetterImport(
            $this->channel,
            self::DEAD_LETTER_QUEUE,
            new DeadLetterImporter($store ?? $this->store, new JsonDeserializer(), new DeadLetterBuilder()),
        );
    }

    /** @return array<string, mixed> one x-death entry as the broker writes it */
    private static function death(string $queue, string $reason, int $count = 1, int $time = self::TIME): array
    {
        return [
            'count' => $count,
            'reason' => $reason,
            'queue' => $queue,
            'time' => new DateTime('@'.$time),
            'exchange' => '',
            'routing-keys' => [$queue],
        ];
    }

    /** @param list<array<string, mixed>> $deaths */
    private function moved(int $tag, string $messageId, array $deaths, string $body = '{"order_id":"o-1"}'): AMQPMessage
    {
        return $this->delivery($tag, $messageId, [
            'x-death' => $deaths,
        ], body: $body);
    }

    /** @return list<DeadLetter> */
    private function rows(): array
    {
        return $this->store->list(new DeadLetterFilter());
    }

    // the move from x-death

    public function testTheMoveIsTheNewestEntryThatIsNotExpired(): void
    {
        $this->pending = [$this->moved(1, 'm-1', [
            self::death('orders.wait.100', 'expired', 2, self::TIME + 60),
            self::death('orders', 'delivery_limit', 1, self::TIME),
            self::death('older_q', 'rejected', 5, self::TIME - 60),
        ])];

        self::assertSame(1, $this->import()->run());

        $row = $this->rows()[0];
        self::assertSame('orders', $row->source);
        self::assertSame(DeliveryLimitExceeded::class, $row->errorClass);
        self::assertSame("Moved by the transport: reason 'delivery_limit', queue 'orders', count 1", $row->errorMessage);
        self::assertSame(self::TIME * 1000, $row->failedAt, 'the \DateTime time becomes epoch milliseconds');
        self::assertTrue($row->replayable);
    }

    public function testOnlyExpiredEntriesGiveNoMove(): void
    {
        $this->pending = [$this->moved(1, 'm-1', [
            self::death('orders.wait.100', 'expired'),
            self::death('orders.wait.200', 'expired'),
        ])];

        $this->import()->run();

        $row = $this->rows()[0];
        self::assertSame(self::DEAD_LETTER_QUEUE, $row->source, 'case 4: the dead letter queue');
        self::assertSame(MovedByTransport::class, $row->errorClass);
        self::assertSame("Moved by the transport: reason 'unknown', queue 'orders.dlq'", $row->errorMessage);
        self::assertFalse($row->replayable);
    }

    public function testNoDeathHeaderGivesNoMove(): void
    {
        $this->pending = [$this->delivery(1, 'm-1')];

        $this->import()->run();

        self::assertSame(self::DEAD_LETTER_QUEUE, $this->rows()[0]->source);
        self::assertFalse($this->rows()[0]->replayable);
    }

    public function testADeathHeaderThatIsNotAListGivesNoMove(): void
    {
        $this->pending = [$this->delivery(1, 'm-1', [
            'x-death' => 'orders',
        ])];

        $this->import()->run();

        self::assertSame(self::DEAD_LETTER_QUEUE, $this->rows()[0]->source);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incompleteEntries(): iterable
    {
        $entry = [
            'count' => 1,
            'reason' => 'delivery_limit',
            'queue' => 'bad_q',
        ];
        yield 'no queue' => [
            array_diff_key($entry, [
                'queue' => true,
            ])];
        yield 'no reason' => [
            array_diff_key($entry, [
                'reason' => true,
            ])];
        yield 'no count' => [
            array_diff_key($entry, [
                'count' => true,
            ])];
        yield 'queue not a string' => [[
            'queue' => 5,
        ] + $entry];
        yield 'reason not a string' => [[
            'reason' => 5,
        ] + $entry];
        yield 'count not an int' => [[
            'count' => '1',
        ] + $entry];
    }

    /** @param array<string, mixed> $incomplete */
    #[DataProvider('incompleteEntries')]
    public function testAnIncompleteEntryIsSkipped(array $incomplete): void
    {
        $this->pending = [$this->moved(1, 'm-1', [$incomplete, self::death('orders', 'rejected', 3)])];

        $this->import()->run();

        self::assertSame('orders', $this->rows()[0]->source);
        self::assertSame("Moved by the transport: reason 'rejected', queue 'orders', count 3", $this->rows()[0]->errorMessage);
    }

    public function testAnEntryThatIsNotATableIsSkipped(): void
    {
        $this->pending = [$this->delivery(1, 'm-1', [
            'x-death' => ['text', self::death('orders', 'rejected')],
        ])];

        $this->import()->run();

        self::assertSame('orders', $this->rows()[0]->source);
    }

    public function testAnEntryWithNoTimeUsesTheClock(): void
    {
        $entry = self::death('orders', 'delivery_limit');
        unset($entry['time']);
        $this->pending = [$this->moved(1, 'm-1', [$entry])];
        $before = (int) (microtime(true) * 1000);

        $this->import()->run();

        $after = (int) (microtime(true) * 1000);
        self::assertGreaterThanOrEqual($before, $this->rows()[0]->failedAt);
        self::assertLessThanOrEqual($after, $this->rows()[0]->failedAt);
    }

    public function testTheBrokerHeadersStayInTheRow(): void
    {
        $this->pending = [$this->delivery(1, 'm-1', [
            'x-death' => [self::death('orders', 'delivery_limit')],
            'x-first-death-queue' => 'orders',
            'x-first-death-reason' => 'delivery_limit',
            'x-last-death-queue' => 'orders',
            'x-delivery-count' => 2,
            'x-attempt' => 3,
        ])];

        $this->import()->run();

        $row = $this->rows()[0];
        self::assertSame('orders', $row->headers['x-death'][0]['queue']);
        self::assertSame('orders', $row->headers['x-first-death-queue']);
        self::assertSame('delivery_limit', $row->headers['x-first-death-reason']);
        self::assertSame('orders', $row->headers['x-last-death-queue']);
        self::assertSame(2, $row->headers['x-delivery-count']);
        self::assertSame(3, $row->attempts, 'the attempt of x-attempt, never the delivery count');
    }

    // the ack, the nack and the loop

    public function testEachMessageIsAckedAfterItsStore(): void
    {
        $acksAtStore = [];
        $store = $this->storeThat(function () use (&$acksAtStore): void {
            $acksAtStore[] = $this->acked;
        });
        $this->pending = [
            $this->moved(4, 'm-1', [self::death('orders', 'delivery_limit')]),
            $this->moved(5, 'm-2', [self::death('orders', 'delivery_limit')]),
        ];

        self::assertSame(2, $this->import($store)->run());

        self::assertSame([[], [4]], $acksAtStore, 'no ack before the store of its message');
        self::assertSame([4, 5], $this->acked);
        self::assertSame([], $this->returnCalls, 'no nack');
        self::assertSame([self::DEAD_LETTER_QUEUE, self::DEAD_LETTER_QUEUE, self::DEAD_LETTER_QUEUE], $this->gets);
    }

    public function testAStoreThatThrowsNacksWithRequeueAndRunThrowsTheSameError(): void
    {
        $error = new RuntimeException('database down');
        $this->pending = [
            $this->moved(4, 'm-1', [self::death('orders', 'delivery_limit')]),
            $this->moved(5, 'm-2', [self::death('orders', 'delivery_limit')]),
        ];

        try {
            $this->import($this->failingStore($error))->run();
            self::fail('the store error must end run()');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e);
        }

        self::assertSame([['basic_nack', 4, false, true]], $this->returnCalls, 'one nack with requeue of that tag');
        self::assertSame([], $this->acked);
        self::assertCount(1, $this->pending, 'the loop does not continue');
    }

    public function testANackThatFailsDoesNotHideTheStoreError(): void
    {
        $error = new RuntimeException('database down');
        $this->failures['basic_nack'] = new RuntimeException('connection lost');
        $this->pending = [$this->moved(4, 'm-1', [self::death('orders', 'delivery_limit')])];

        try {
            $this->import($this->failingStore($error))->run();
            self::fail('the store error must end run()');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e);
        }

        self::assertSame([['basic_nack', 4, false, true]], $this->returnCalls);
        self::assertSame([], $this->acked);
    }

    public function testTheLimitStopsTheLoop(): void
    {
        $this->pending = [
            $this->delivery(1, 'm-1'),
            $this->delivery(2, 'm-2'),
            $this->delivery(3, 'm-3'),
        ];

        self::assertSame(2, $this->import()->run(2));

        self::assertSame([1, 2], $this->acked);
        self::assertCount(1, $this->pending);
        self::assertCount(2, $this->gets, 'no basic.get after the limit');
        self::assertCount(2, $this->rows());
    }

    public function testNoLimitImportsUntilTheQueueIsEmpty(): void
    {
        $this->pending = [$this->delivery(1, 'm-1'), $this->delivery(2, 'm-2')];

        self::assertSame(2, $this->import()->run());

        self::assertSame([1, 2], $this->acked);
        self::assertSame([], $this->pending);
    }

    public function testAnEmptyQueueReturnsZero(): void
    {
        self::assertSame(0, $this->import()->run());

        self::assertSame([self::DEAD_LETTER_QUEUE], $this->gets);
        self::assertSame([], $this->acked);
        self::assertSame([], $this->rows());
    }

    private function failingStore(RuntimeException $error): DeadLetterStore
    {
        return $this->storeThat(static fn () => throw $error);
    }

    /** The in-memory store of the test, with $beforeStore called before each store. */
    private function storeThat(\Closure $beforeStore): DeadLetterStore
    {
        return new class($this->store, $beforeStore) implements DeadLetterStore {
            public function __construct(
                private readonly InMemoryDeadLetterStore $inner,
                private readonly \Closure $beforeStore,
            ) {}

            public function store(DeadLetter $deadLetter): void
            {
                ($this->beforeStore)($deadLetter);
                $this->inner->store($deadLetter);
            }

            public function find(string $id): ?DeadLetter
            {
                return $this->inner->find($id);
            }

            public function list(DeadLetterFilter $filter, int $limit = 100, int $offset = 0): array
            {
                return $this->inner->list($filter, $limit, $offset);
            }

            public function count(DeadLetterFilter $filter): int
            {
                return $this->inner->count($filter);
            }

            public function ids(DeadLetterFilter $filter, int $limit): array
            {
                return $this->inner->ids($filter, $limit);
            }

            public function stats(DeadLetterFilter $filter): array
            {
                return $this->inner->stats($filter);
            }

            public function markReplayed(string $id): bool
            {
                return $this->inner->markReplayed($id);
            }

            public function purge(DeadLetterFilter $filter): int
            {
                return $this->inner->purge($filter);
            }
        };
    }
}
