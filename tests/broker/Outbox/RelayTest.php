<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Outbox;

use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Testing\FakeOutboxPublisher;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use Freyr\MessageBroker\Testing\RecordingErrorHandler;
use Freyr\MessageBroker\Testing\RecordingEvents;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Worker\WorkerLimits;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

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

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
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

    public function testTheRunStopsAfterTheBatchThatReachesTheMessageLimit(): void
    {
        $this->insertFresh(25);
        $publisher = new FakeOutboxPublisher();
        $relay = $this->relay($publisher, batchSize: 10);

        $relay->run(new WorkerLimits(maxMessages: 15));

        self::assertSame([10, 10], $this->store->claimSizes, 'the second batch reaches 20, above the limit of 15');
        self::assertSame(2, $this->store->drainCalls);
        self::assertCount(5, $this->store->unpublishedIds());
    }

    public function testTheRunStopsAtOnceWhenABatchReachesTheMessageLimitExactly(): void
    {
        $this->insertFresh(25);
        $relay = $this->relay(new FakeOutboxPublisher(), batchSize: 10);

        $relay->run(new WorkerLimits(maxMessages: 10));

        self::assertSame(1, $this->store->drainCalls);
    }

    public function testTheTimeLimitStopsTheRun(): void
    {
        $clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($clock);
        $relay = $this->relay(new FakeOutboxPublisher());
        $this->store->onDrain = static function () use ($clock): void {
            $clock->sleep(4);
        };

        $relay->run(new WorkerLimits(maxSeconds: 10));

        self::assertSame(3, $this->store->drainCalls, 'the clock shows 4 s, 8 s, then 12 s');
    }

    public function testTheTimeLimitStopsAnIdleRunAfterTheIdleSleep(): void
    {
        $clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($clock);
        $relay = $this->relay(new FakeOutboxPublisher());
        $this->store->onDrain = static function () use ($clock): void {
            $clock->sleep(10);
        };

        $relay->run(new WorkerLimits(maxSeconds: 10));

        self::assertSame(1, $this->store->drainCalls, 'an empty pass is checked too');
    }

    public function testAMemoryLimitOfOneStopsTheRunAfterTheFirstPass(): void
    {
        $this->insertFresh(3);
        $relay = $this->relay(new FakeOutboxPublisher());

        $relay->run(new WorkerLimits(maxMemoryBytes: 1));

        self::assertSame(1, $this->store->drainCalls);
        self::assertSame([], $this->store->unpublishedIds(), 'the pass that ran is complete');
    }

    public function testAMemoryLimitOfPhpIntMaxDoesNotStopTheRun(): void
    {
        $relay = $this->relay(new FakeOutboxPublisher());
        $this->store->onDrain = static function (int $call) use ($relay): void {
            if ($call === 3) {
                $relay->stop();
            }
        };

        $relay->run(new WorkerLimits(maxMemoryBytes: PHP_INT_MAX));

        self::assertSame(3, $this->store->drainCalls);
        self::assertSame([], $this->logger->records);
    }

    public function testARunWithNoLimitsRunsUntilStop(): void
    {
        $relay = $this->relay(new FakeOutboxPublisher());
        $this->store->onDrain = static function (int $call) use ($relay): void {
            if ($call === 3) {
                $relay->stop();
            }
        };

        $relay->run();

        self::assertSame(3, $this->store->drainCalls);
    }

    public function testALimitStopAndStopEndTheRunTheSameWay(): void
    {
        $this->insertFresh(2);
        $limited = $this->relay(new FakeOutboxPublisher());
        $limited->run(new WorkerLimits(maxMessages: 1));
        $limitedCalls = $this->store->drainCalls;

        $stopped = $this->relay(new FakeOutboxPublisher());
        $stopped->stop();

        // A stopped relay does not start another pass, so a second run() returns at once.
        $limited->run();
        $stopped->run();

        self::assertSame($limitedCalls, $this->store->drainCalls);
    }

    public function testALimitStopLogsTheReasonTheCountsTheElapsedTimeAndTheMemory(): void
    {
        $clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($clock);
        $this->insertFresh(3);
        $relay = $this->relay(new FakeOutboxPublisher());
        $this->store->onDrain = static function () use ($clock): void {
            $clock->sleep(1.5);
        };

        $relay->run(new WorkerLimits(maxMessages: 3));

        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::INFO, $record['level']);
        self::assertSame('Worker limit reached; the worker stops', $record['message']);
        $context = $record['context'];
        self::assertSame('message limit', $context['reason']);
        self::assertSame(self::LANE, $context['lane']);
        self::assertSame(3, $context['messages']);
        self::assertSame(0, $context['failures']);
        self::assertSame(1.5, $context['elapsedSeconds']);
        self::assertIsInt($context['memoryBytes']);
        self::assertGreaterThan(0, $context['memoryBytes']);
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
