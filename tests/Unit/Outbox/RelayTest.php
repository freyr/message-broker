<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox;

use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Tests\Fixtures\FakeOutboxPublisher;
use Freyr\MessageBroker\Tests\Fixtures\InMemoryOutboxStore;
use Freyr\MessageBroker\Tests\Fixtures\RecordingErrorHandler;
use Freyr\MessageBroker\Tests\Fixtures\RecordingEvents;
use Freyr\MessageBroker\Tests\Fixtures\RecordingLogger;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use ReflectionClass;
use RuntimeException;

/** Relay loop with a fake publisher and an in-memory store: no AMQP, no database. */
final class RelayTest extends TestCase
{
    private const string LANE = 'unit';

    private int $sequence = 0;
    private InMemoryOutboxStore $store;
    private RecordingLogger $logger;
    private RecordingEvents $events;
    private RecordingErrorHandler $errors;

    protected function setUp(): void
    {
        $this->store = new InMemoryOutboxStore();
        $this->logger = new RecordingLogger();
        $this->events = new RecordingEvents();
        $this->errors = new RecordingErrorHandler();
    }

    public function testFreshRowsArePublishedInChunksOfMaxBatchSize(): void
    {
        $ids = $this->insertFresh(25);
        $publisher = new FakeOutboxPublisher(10);

        self::assertSame(25, $this->relay($publisher)->drainOnce());

        self::assertSame([10, 10, 5], array_map('count', $publisher->calls));
        self::assertSame($ids, array_merge(...$publisher->calls));
        self::assertSame([], $this->store->unpublishedIds());
    }

    public function testAMaxBatchSizeOfPhpIntMaxGivesOneCall(): void
    {
        $this->insertFresh(25);
        $publisher = new FakeOutboxPublisher(PHP_INT_MAX);

        self::assertSame(25, $this->relay($publisher)->drainOnce());

        self::assertSame([25], array_map('count', $publisher->calls));
    }

    public function testAClaimOfExactlyOneChunkIsOneCall(): void
    {
        $this->insertFresh(10);
        $publisher = new FakeOutboxPublisher(10);

        $this->relay($publisher)->drainOnce();

        self::assertSame([10], array_map('count', $publisher->calls));
    }

    public function testRetriedRowsGoAloneAndBeforeTheFreshRows(): void
    {
        $fresh = $this->insertFresh(3);
        $retried = [$this->insert(attempts: 2), $this->insert(attempts: 1)];
        $publisher = new FakeOutboxPublisher(10);

        self::assertSame(5, $this->relay($publisher)->drainOnce());

        self::assertSame([[$retried[0]], [$retried[1]], $fresh], $publisher->calls);
    }

    public function testRefusedIdsOfOneChunkBackOffWhileTheRestOfTheChunkIsPublished(): void
    {
        $ids = $this->insertFresh(5);
        $publisher = new FakeOutboxPublisher(3);
        $publisher->refuse = [$ids[1], $ids[4]];

        $before = EpochMillis::now();
        self::assertSame(3, $this->relay($publisher, Backoff::exponential(60_000, 600_000))->drainOnce());

        foreach ([$ids[0], $ids[2], $ids[3]] as $id) {
            self::assertTrue($this->store->isPublished($id));
        }
        foreach ([$ids[1], $ids[4]] as $id) {
            self::assertFalse($this->store->isPublished($id));
            self::assertSame(1, $this->store->row($id)->attempts);
            self::assertGreaterThanOrEqual($before + 60_000, $this->store->row($id)->availableAt);
        }
        self::assertCount(2, $this->logger->records);
        self::assertSame(LogLevel::WARNING, $this->logger->records[0]['level']);
        self::assertSame('Broker nacked messages; they back off', $this->logger->records[0]['message']);
        self::assertSame([$ids[1]], $this->logger->records[0]['context']['ids']);
        self::assertSame([$ids[4]], $this->logger->records[1]['context']['ids']);
        self::assertSame([], $this->errors->calls);
    }

    public function testAThrowInTheSecondChunkOfThreeBacksOffThatChunkAndLeavesTheThirdUntouched(): void
    {
        $ids = $this->insertFresh(25);
        $publisher = new FakeOutboxPublisher(10);
        $publisher->throwOnCall = [2];
        $relay = $this->relay($publisher, Backoff::exponential(60_000, 600_000));

        try {
            $relay->drainOnce();
            self::fail('The publisher failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame('channel closed', $error->getMessage());
        }

        self::assertCount(2, $publisher->calls, 'chunk 3 is not attempted');
        foreach (array_slice($ids, 0, 10) as $id) {
            self::assertTrue($this->store->isPublished($id), 'chunk 1 keeps its result');
        }
        foreach (array_slice($ids, 10, 10) as $id) {
            self::assertFalse($this->store->isPublished($id));
            self::assertSame(1, $this->store->row($id)->attempts, 'chunk 2 backs off');
            self::assertGreaterThan(EpochMillis::now(), $this->store->row($id)->availableAt);
        }
        foreach (array_slice($ids, 20) as $id) {
            self::assertSame(0, $this->store->row($id)->attempts, 'chunk 3 is untouched');
            self::assertNull($this->store->row($id)->availableAt, 'chunk 3 stays due');
        }
        self::assertSame(10, $this->events->records[0]['context']['count']);
    }

    public function testAThrowIsReportedWithTheFailedRowsAndTheErrorIsRethrownAfterTheOutcomeIsApplied(): void
    {
        $ids = $this->insertFresh(3);
        $publisher = new FakeOutboxPublisher(2);
        $publisher->throwOnCall = [1];

        try {
            $this->relay($publisher)->drainOnce();
            self::fail('The publisher failure must propagate.');
        } catch (RuntimeException) {
        }

        self::assertCount(1, $this->errors->calls);
        self::assertSame(self::LANE, $this->errors->calls[0]['context']['lane']);
        self::assertSame(2, $this->errors->calls[0]['context']['rows']);
        self::assertSame([$ids[0], $ids[1]], $this->errors->calls[0]['context']['ids']);
        self::assertSame(LogLevel::WARNING, $this->logger->records[0]['level']);
        self::assertSame(
            'Relay publish failed; the failed rows back off and the worker stops',
            $this->logger->records[0]['message'],
        );
        self::assertInstanceOf(RuntimeException::class, $this->logger->records[0]['context']['exception']);
        self::assertSame(1, $this->store->row($ids[0])->attempts);
        self::assertSame(0, $this->store->row($ids[2])->attempts);
    }

    public function testAThrowOfARetriedRowBacksOffThatRowAndLeavesTheRestUntouched(): void
    {
        $retried = [$this->insert(attempts: 1), $this->insert(attempts: 1)];
        $fresh = $this->insertFresh(2);
        $publisher = new FakeOutboxPublisher(10);
        $publisher->throwOnCall = [2];

        try {
            $this->relay($publisher)->drainOnce();
            self::fail('The publisher failure must propagate.');
        } catch (RuntimeException) {
        }

        self::assertTrue($this->store->isPublished($retried[0]));
        self::assertSame(2, $this->store->row($retried[1])->attempts);
        self::assertSame(0, $this->store->row($fresh[0])->attempts);
        self::assertCount(2, $publisher->calls);
    }

    public function testTheRelayedEventCarriesTheLaneAndTheTotalCountOfAllChunks(): void
    {
        $this->insertFresh(25);

        $this->relay(new FakeOutboxPublisher(10))->drainOnce();

        self::assertSame([BrokerEvents::RELAYED], $this->events->names());
        self::assertSame([
            'lane' => self::LANE,
            'count' => 25,
        ], $this->events->records[0]['context']);
    }

    public function testNoEventIsRecordedWhenNothingIsPublished(): void
    {
        $id = $this->insert();
        $publisher = new FakeOutboxPublisher();
        $publisher->refuse = [$id];

        self::assertSame(0, $this->relay($publisher, Backoff::exponential(60_000, 600_000))->drainOnce());
        self::assertSame(0, $this->relay(new FakeOutboxPublisher())->drainOnce(), 'the row is backing off');

        self::assertSame([], $this->events->names());
    }

    public function testAnEmptyClaimCallsNoPublisher(): void
    {
        $publisher = new FakeOutboxPublisher();

        self::assertSame(0, $this->relay($publisher)->drainOnce());

        self::assertSame([], $publisher->calls);
    }

    public function testTheBatchSizeLimitsTheClaim(): void
    {
        $this->insertFresh(7);
        $publisher = new FakeOutboxPublisher();

        self::assertSame(3, $this->relay($publisher, batchSize: 3)->drainOnce());

        self::assertSame([3], $this->store->claimSizes);
    }

    public function testOnlyTheOwnLaneIsDrained(): void
    {
        $this->insertFresh(2);
        $other = new OutboxRecord('01900000-0000-7000-8000-00000000ffff', 'other', 'm.n', 'b', [], 1);
        $this->store->insert($other);

        self::assertSame(2, $this->relay(new FakeOutboxPublisher())->drainOnce());

        self::assertFalse($this->store->isPublished($other->id));
    }

    public function testRunDrainsSleepsWhenIdleAndStopsOnStop(): void
    {
        $this->insertFresh(3);
        $publisher = new FakeOutboxPublisher();
        $relay = $this->relay($publisher, idleSleepMs: 20);
        $this->store->onDrain = static function (int $call) use ($relay): void {
            if ($call === 3) {
                $relay->stop();
            }
        };

        $start = hrtime(true);
        $relay->run();
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        self::assertSame(3, $this->store->drainCalls, 'drain, idle drain, then the drain that stops');
        self::assertSame([3], array_map('count', $publisher->calls));
        self::assertGreaterThanOrEqual(20.0, $elapsedMs, 'the idle drain sleeps at least the idle interval');
    }

    public function testRunDoesNotSleepAfterAProductiveDrain(): void
    {
        $this->insertFresh(1);
        $publisher = new FakeOutboxPublisher();
        $relay = $this->relay($publisher, idleSleepMs: 2_000);
        $publisher->onPublish = static fn () => $relay->stop();

        $start = hrtime(true);
        $relay->run();

        self::assertSame(1, $this->store->drainCalls);
        self::assertLessThan(1_000.0, (hrtime(true) - $start) / 1e6, 'a productive drain does not sleep');
    }

    public function testTheConstructorDefaults(): void
    {
        $defaults = [];
        foreach ((new ReflectionClass(Relay::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isDefaultValueAvailable() && !is_object($parameter->getDefaultValue())) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        self::assertSame([
            'lane' => 'default',
            'batchSize' => 500,
            'backoff' => null,
            'errorHandler' => null,
            'idleSleepMs' => 200,
            'events' => null,
        ], $defaults);
    }

    public function testARunPropagatesAPublisherFailure(): void
    {
        $this->insertFresh(1);
        $publisher = new FakeOutboxPublisher();
        $publisher->throwOnCall = [1];

        $this->expectException(RuntimeException::class);

        $this->relay($publisher)->run();
    }

    private function relay(
        FakeOutboxPublisher $publisher,
        ?Backoff $backoff = null,
        int $batchSize = 500,
        int $idleSleepMs = 1,
    ): Relay {
        return new Relay(
            outbox: $this->store,
            publisher: $publisher,
            lane: self::LANE,
            batchSize: $batchSize,
            backoff: $backoff,
            errorHandler: $this->errors,
            idleSleepMs: $idleSleepMs,
            logger: $this->logger,
            events: $this->events,
        );
    }

    /** @return list<string> */
    private function insertFresh(int $count): array
    {
        return array_map(fn (): string => $this->insert(), range(1, $count));
    }

    private function insert(int $attempts = 0): string
    {
        $id = sprintf('01900000-0000-7000-8000-%012d', ++$this->sequence);
        $this->store->insert(new OutboxRecord($id, self::LANE, 'order.placed', '{}', [], 1_749_722_400_000 + $this->sequence, $attempts));

        return $id;
    }
}
