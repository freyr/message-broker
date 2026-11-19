<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\DlqReplayCommand;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Testing\InMemoryTransaction;
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DlqReplayCommandTest extends TestCase
{
    private const string RAW = 'raw bytes';

    private MockClock $clock;

    private InMemoryDeadLetterStore $store;

    private RecordingDeadLetterStore $replayStore;

    private RecordingOutboxStore $outbox;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-06-15 12:00:00 UTC');
        Clock::set($this->clock);
        $this->store = new InMemoryDeadLetterStore();
        $this->replayStore = new RecordingDeadLetterStore($this->store);
        $this->outbox = new RecordingOutboxStore();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testReplayOfOneIdReportsTheLane(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $tester = $this->tester();

        $status = $tester->execute([
            'id' => 'dl-1',
            '--lane' => 'orders',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("Replayed dl-1 into lane 'orders'.\n", $tester->getDisplay());
        self::assertCount(1, $this->outbox->inserted);
        self::assertSame('orders', $this->outbox->inserted[0]->lane);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusalsOfOneId(): iterable
    {
        yield 'not found' => ['nope', "Dead letter 'nope' not found.\n"];
        yield 'already replayed' => ['dl-done', "Dead letter 'dl-done' is already replayed.\n"];
        yield 'not replayable' => ['dl-raw', "Dead letter 'dl-raw' is not replayable: marked not replayable.\n"];
    }

    #[DataProvider('refusalsOfOneId')]
    public function testARefusedReplayOfOneIdFailsWithTheIdAndTheReason(string $id, string $display): void
    {
        $this->seedRefusedRows();
        $tester = $this->tester();

        $status = $tester->execute([
            'id' => $id,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame($display, $tester->getDisplay());
        self::assertSame([], $this->outbox->inserted);
    }

    public function testDryRunOfOneIdReplaysNothing(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $tester = $this->tester();

        $status = $tester->execute([
            'id' => 'dl-1',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("[dry-run] would replay dl-1 into lane 'default'.\n", $tester->getDisplay());
        self::assertSame([], $this->outbox->inserted);
        self::assertNull($this->store->find('dl-1')?->replayedAt);
    }

    #[DataProvider('refusalsOfOneId')]
    public function testDryRunOfOneIdReportsARefusal(string $id, string $display): void
    {
        $this->seedRefusedRows();
        $tester = $this->tester();

        $status = $tester->execute([
            'id' => $id,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame($display, $tester->getDisplay());
        self::assertSame([], $this->outbox->inserted);
    }

    public function testNeitherIdNorAllIsInvalid(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("Provide a dead letter id or --all.\n", $tester->getDisplay());
    }

    public function testAllDryRunListsAFiveRowSampleOfNotYetReplayedRows(): void
    {
        foreach (range(1, 7) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: 1_750_000_000_000 + $i));
        }
        $this->store->add(DeadLetterFactory::make('dl-done', failedAt: 1_750_000_000_100, replayedAt: 1_750_000_000_200));
        $tester = $this->tester();

        $status = $tester->execute([
            '--all' => true,
            '--dry-run' => true,
            '--lane' => 'orders',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            <<<'OUT'
                [dry-run] would replay 7 dead letters into lane 'orders'.
                  dl-7  order.placed
                  dl-6  order.placed
                  dl-5  order.placed
                  dl-4  order.placed
                  dl-3  order.placed

                OUT,
            $tester->getDisplay(),
        );
        self::assertSame([], $this->outbox->inserted);
        self::assertEquals([[
            'filter' => $this->bulkFilter(),
        ]], $this->store->callsOf('count'));
        self::assertEquals([[
            'filter' => $this->bulkFilter(),
            'limit' => 5,
            'offset' => 0,
        ]], $this->store->callsOf('list'));
        self::assertSame([], $this->store->callsOf('ids'));
    }

    public function testAllDryRunWithALimitCountsAtMostTheLimit(): void
    {
        foreach (range(1, 5) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: 1_750_000_000_000 + $i));
        }
        $tester = $this->tester();

        $tester->execute([
            '--all' => true,
            '--dry-run' => true,
            '--limit' => '2',
        ]);

        self::assertStringStartsWith("[dry-run] would replay 2 dead letters into lane 'default'.\n", $tester->getDisplay());
        self::assertSame([], $this->outbox->inserted);
    }

    public function testAllPassesTheFiltersToCountAndIds(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', failedAt: EpochMillis::now() - 1_000));
        $tester = $this->tester();

        $tester->execute([
            '--all' => true,
            '--force' => true,
            '--name' => 'order.placed',
            '--source' => 'orders_q',
            '--since' => '2h',
        ]);

        $expected = new DeadLetterFilter(
            messageName: 'order.placed',
            source: 'orders_q',
            failedFromMs: EpochMillis::now() - 7_200_000,
            failedUntilMs: EpochMillis::now(),
            replayed: false,
            replayable: true,
        );
        self::assertEquals([[
            'filter' => $expected,
        ]], $this->store->callsOf('count'));
        self::assertEquals($expected, $this->store->callsOf('ids')[0]['filter']);
        self::assertCount(1, $this->outbox->inserted);
    }

    public function testEachFilterOptionOfAllReachesCountAndIds(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--all' => true,
            '--force' => true,
            '--error' => 'LogicException',
            '--message-id' => 'm-1',
            '--until' => '1h',
            '--min-attempts' => '2',
            '--replayable' => true,
        ]);

        $expected = new DeadLetterFilter(
            errorClass: 'LogicException',
            messageId: 'm-1',
            failedUntilMs: EpochMillis::now() - 3_600_000,
            minAttempts: 2,
            replayed: false,
            replayable: true,
        );
        self::assertEquals($expected, $this->store->callsOf('count')[0]['filter'], 'an earlier --until is the bound');
        self::assertEquals($expected, $this->store->callsOf('ids')[0]['filter']);
    }

    public function testAnUntilAfterTheStartIsBoundedByTheStart(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--all' => true,
            '--force' => true,
            '--until' => '2026-06-15T13:00:00Z',
        ]);

        self::assertEquals($this->bulkFilter(), $this->store->callsOf('ids')[0]['filter']);
    }

    public function testNotReplayableReplacesTheDefaultReplayableCondition(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-raw', replayable: false));
        $tester = $this->tester();

        $status = $tester->execute([
            '--all' => true,
            '--force' => true,
            '--not-replayable' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status, 'a skip is not a failure');
        self::assertEquals($this->bulkFilter(replayable: false), $this->store->callsOf('ids')[0]['filter']);
        self::assertSame(
            "Skipped: Dead letter 'dl-raw' is not replayable: marked not replayable.\n"
            ."Replayed 0, skipped 1 (not replayable), skipped 0 (already replayed), failed 0 into lane 'default'.\n",
            $tester->getDisplay(),
        );
    }

    public function testAllWithNotReplayedKeepsTheReplayedConditionFalse(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--all' => true,
            '--force' => true,
            '--not-replayed' => true,
        ]);

        self::assertEquals($this->bulkFilter(), $this->store->callsOf('count')[0]['filter']);
        self::assertEquals($this->bulkFilter(), $this->store->callsOf('ids')[0]['filter']);
    }

    public function testAllWithReplayedIsInvalidAndReplaysNothing(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-done', replayedAt: 1_750_000_000_200));
        $tester = $this->tester();

        $status = $tester->execute([
            '--all' => true,
            '--force' => true,
            '--replayed' => true,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("--replayed cannot be used with --all: a bulk replay selects the rows that are not replayed.\n", $tester->getDisplay());
        self::assertSame([], $this->store->callsOf('count'));
        self::assertSame([], $this->store->callsOf('list'));
        self::assertSame([], $this->store->callsOf('ids'));
        self::assertSame([], $this->outbox->inserted);
    }

    public function testAllWithATimeThatOverflowsIsInvalid(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            '--all' => true,
            '--force' => true,
            '--since' => '106751991168d',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("Invalid time '106751991168d' for --since: the duration is too large\n", $tester->getDisplay());
        self::assertSame([], $this->store->callsOf('count'));
        self::assertSame([], $this->outbox->inserted);
    }

    public function testAllWithAnInvalidFilterOptionIsInvalid(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            '--all' => true,
            '--force' => true,
            '--replayable' => true,
            '--not-replayable' => true,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("Use --replayable or --not-replayable, not both.\n", $tester->getDisplay());
        self::assertSame([], $this->store->callsOf('count'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function badLimitsAndRates(): iterable
    {
        foreach (['limit', 'rate'] as $option) {
            yield "{$option} zero" => [$option, '0'];
            yield "{$option} negative" => [$option, '-1'];
            yield "{$option} text" => [$option, 'ten'];
            yield "{$option} fraction" => [$option, '1.5'];
            yield "{$option} trailing newline" => [$option, "2\n"];
        }
    }

    #[DataProvider('badLimitsAndRates')]
    public function testALimitOrRateThatIsNotAPositiveIntegerIsInvalid(string $option, string $value): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $tester = $this->tester();

        $status = $tester->execute([
            '--all' => true,
            '--force' => true,
            "--{$option}" => $value,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("Invalid --{$option} '{$value}': expected an integer of 1 or more\n", $tester->getDisplay());
        self::assertSame([], $this->store->callsOf('count'));
        self::assertSame([], $this->outbox->inserted);
    }

    public function testAllReportsTheFourCountsAndASkipOrAFailureDoesNotStopTheRun(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', failedAt: 1));
        $this->store->add(DeadLetterFactory::make('dl-2', failedAt: 2));
        $this->store->add(DeadLetterFactory::make('dl-3', failedAt: 3, body: self::RAW));
        $this->store->add(DeadLetterFactory::make('dl-4', failedAt: 4));
        $this->store->add(DeadLetterFactory::make('dl-5', failedAt: 5));
        $this->store->add(DeadLetterFactory::make('dl-6', failedAt: 6));
        $this->replayStore->markReplayedFails['dl-2'] = new RuntimeException('database down');
        $this->replayStore->markReplayedResultFor['dl-4'] = false; // another run replayed it after ids()
        $this->replayStore->missing['dl-5'] = true;                // a purge removed it after ids()
        $tester = $this->tester();

        $status = $tester->execute([
            '--all' => true,
            '--force' => true,
            '--lane' => 'orders',
        ]);

        self::assertSame(Command::FAILURE, $status, 'a failed row gives exit 1');
        self::assertSame(
            "Failed dl-2: RuntimeException: database down\n"
            ."Skipped: Dead letter 'dl-3' is not replayable: body is not a replayable wire document "
            ."(stage-1 dead letters store raw bytes and are not replayable).\n"
            ."Replayed 2, skipped 1 (not replayable), skipped 2 (already replayed), failed 1 into lane 'orders'.\n",
            $tester->getDisplay(),
        );
        self::assertSame(['m-dl-1', 'm-dl-6'], self::insertedIds($this->outbox->inserted));
    }

    public function testASkippedRowIsNotAskedAgain(): void
    {
        foreach (range(1, 3) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: $i, body: self::RAW));
        }
        $tester = $this->tester(batchSize: 2);

        $status = $tester->execute([
            '--all' => true,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringEndsWith(
            "Replayed 0, skipped 3 (not replayable), skipped 0 (already replayed), failed 0 into lane 'default'.\n",
            $tester->getDisplay(),
        );
        self::assertSame(3, substr_count($tester->getDisplay(), 'Skipped: '), 'each row once');
        self::assertSame([2, 4, 5], array_column($this->store->callsOf('ids'), 'limit'), 'a batch plus the held-back rows');
    }

    public function testAllDrainsInBatchesOfFiveHundredByDefault(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $tester = $this->tester();

        $status = $tester->execute([
            '--all' => true,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            "Replayed 1, skipped 0 (not replayable), skipped 0 (already replayed), failed 0 into lane 'default'.\n",
            $tester->getDisplay(),
        );
        self::assertSame([500, 500], array_column($this->store->callsOf('ids'), 'limit'));
    }

    public function testAllDrainsInTheConfiguredBatchSizeOldestFirst(): void
    {
        foreach (range(1, 3) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: 4 - $i));
        }
        $tester = $this->tester(batchSize: 2);

        $tester->execute([
            '--all' => true,
            '--force' => true,
        ]);

        self::assertStringStartsWith('Replayed 3,', $tester->getDisplay());
        self::assertSame([2, 2, 2], array_column($this->store->callsOf('ids'), 'limit'));
        self::assertSame(['m-dl-3', 'm-dl-2', 'm-dl-1'], self::insertedIds($this->outbox->inserted));
    }

    public function testTheLimitCapsTheHandledRowsAndGoesToIds(): void
    {
        foreach (range(1, 5) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: $i));
        }
        $tester = $this->tester();

        $tester->execute([
            '--all' => true,
            '--force' => true,
            '--limit' => '2',
        ]);

        self::assertStringStartsWith('Replayed 2,', $tester->getDisplay());
        self::assertSame([2], array_column($this->store->callsOf('ids'), 'limit'));
        self::assertSame(['m-dl-1', 'm-dl-2'], self::insertedIds($this->outbox->inserted));
    }

    public function testTheLimitCountsSkippedRowsAndSpansBatches(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', failedAt: 1, body: self::RAW));
        foreach (range(2, 5) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: $i));
        }
        $tester = $this->tester(batchSize: 2);

        $tester->execute([
            '--all' => true,
            '--force' => true,
            '--limit' => '3',
        ]);

        self::assertStringEndsWith(
            "Replayed 2, skipped 1 (not replayable), skipped 0 (already replayed), failed 0 into lane 'default'.\n",
            $tester->getDisplay(),
        );
        self::assertSame([2, 2], array_column($this->store->callsOf('ids'), 'limit'), 'the second round: 1 left + 1 held back');
        self::assertSame(['m-dl-2', 'm-dl-3'], self::insertedIds($this->outbox->inserted));
    }

    public function testTheLimitStopsInsideARoundThatReturnsMoreIdsThanAreLeft(): void
    {
        foreach (range(1, 3) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: $i));
        }
        $this->store->add(DeadLetterFactory::make('dl-x', failedAt: 0));
        // The failed row leaves the table during the run (a purge by an operator).
        $this->replayStore = new RecordingDeadLetterStore($this->store, function (string $call): void {
            $this->store->purge(new DeadLetterFilter(messageId: 'm-dl-x'));
        });
        $this->replayStore->markReplayedFails['dl-x'] = new RuntimeException('database down');
        $tester = $this->tester(batchSize: 1);

        $tester->execute([
            '--all' => true,
            '--force' => true,
            '--limit' => '2',
        ]);

        // Round 1 holds dl-x back. Round 2 asks for 1 + 1 ids and gets dl-1 and dl-2: one is left.
        self::assertStringEndsWith(
            "Replayed 1, skipped 0 (not replayable), skipped 0 (already replayed), failed 1 into lane 'default'.\n",
            $tester->getDisplay(),
        );
        self::assertSame([1, 2], array_column($this->store->callsOf('ids'), 'limit'));
        self::assertSame(['m-dl-1'], self::insertedIds($this->outbox->inserted));
    }

    public function testTheRateSpacesTheStartsOfTheReplays(): void
    {
        foreach (range(1, 5) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: $i));
        }
        $tester = $this->tester();
        $before = (float) $this->clock->now()->format('U.u');

        $tester->execute([
            '--all' => true,
            '--force' => true,
            '--rate' => '10',
        ]);

        self::assertCount(5, $this->outbox->inserted);
        self::assertEqualsWithDelta(0.4, (float) $this->clock->now()->format('U.u') - $before, 0.000_01);
        self::assertSame(
            [0, 100, 200, 300, 400],
            array_map(
                fn (OutboxRecord $r): int => $r->createdAt - $this->outbox->inserted[0]->createdAt,
                $this->outbox->inserted,
            ),
        );
    }

    public function testTheRateDoesNotWaitWhenTheReplayTookLonger(): void
    {
        foreach (range(1, 2) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: $i));
        }
        $this->replayStore = new RecordingDeadLetterStore($this->store, function (string $call): void {
            $this->clock->sleep(0.3);
        });
        $tester = $this->tester();
        $before = (float) $this->clock->now()->format('U.u');

        $tester->execute([
            '--all' => true,
            '--force' => true,
            '--rate' => '10',
        ]);

        self::assertEqualsWithDelta(0.6, (float) $this->clock->now()->format('U.u') - $before, 0.000_01, 'no wait: 2 x 0.3 s');
    }

    public function testNoRateMeansNoWait(): void
    {
        foreach (range(1, 3) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: $i));
        }
        $tester = $this->tester();
        $before = $this->clock->now();

        $tester->execute([
            '--all' => true,
            '--force' => true,
        ]);

        self::assertEquals($before, $this->clock->now());
    }

    private function seedRefusedRows(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-done', replayedAt: 1_750_000_000_200));
        $this->store->add(DeadLetterFactory::make('dl-raw', replayable: false));
    }

    private function bulkFilter(bool $replayable = true): DeadLetterFilter
    {
        return new DeadLetterFilter(failedUntilMs: EpochMillis::now(), replayed: false, replayable: $replayable);
    }

    /**
     * @param list<OutboxRecord> $records
     *
     * @return list<string>
     */
    private static function insertedIds(array $records): array
    {
        return array_map(static fn (OutboxRecord $r): string => $r->id, $records);
    }

    private function tester(?int $batchSize = null): CommandTester
    {
        $replay = new ReplayService($this->replayStore, $this->outbox, new InMemoryTransaction(), new JsonWireFormat());

        return new CommandTester($batchSize === null
            ? new DlqReplayCommand($replay, $this->store)
            : new DlqReplayCommand($replay, $this->store, $batchSize));
    }
}
