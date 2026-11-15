<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Console;

use Freyr\MessageBroker\Console\DlqReplayCommand;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\DeadLetterFactory;
use Freyr\MessageBroker\Tests\Fixtures\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Tests\Fixtures\RecordingOutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DlqReplayCommandTest extends TestCase
{
    private InMemoryDeadLetterStore $store;

    private RecordingOutboxStore $outbox;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-06-15 12:00:00 UTC'));
        $this->store = new InMemoryDeadLetterStore();
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

    public function testDryRunOfOneIdReplaysNothing(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $tester = $this->tester();

        $tester->execute([
            'id' => 'dl-1',
            '--dry-run' => true,
        ]);

        self::assertSame("[dry-run] would replay dl-1 into lane 'default'.\n", $tester->getDisplay());
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
        self::assertSame(false, $this->store->callsOf('count')[0]['replayed']);
        self::assertSame(5, $this->store->callsOf('list')[0]['limit']);
        self::assertSame(false, $this->store->callsOf('list')[0]['replayed']);
    }

    public function testAllPassesTheFiltersToCountAndList(): void
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

        $expected = [
            'messageName' => 'order.placed',
            'source' => 'orders_q',
            'sinceMs' => EpochMillis::now() - 7_200_000,
            'replayed' => false,
        ];
        self::assertSame([$expected], $this->store->callsOf('count'));
        $listCall = $this->store->callsOf('list')[0];
        self::assertSame('order.placed', $listCall['messageName']);
        self::assertSame('orders_q', $listCall['source']);
        self::assertSame($expected['sinceMs'], $listCall['sinceMs']);
        self::assertCount(1, $this->outbox->inserted);
    }

    public function testAllDrainsInBatchesOfFiveHundredByDefault(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1'));
        $tester = $this->tester();

        $tester->execute([
            '--all' => true,
            '--force' => true,
        ]);

        self::assertSame("Replayed 1 dead letters into lane 'default'.\n", $tester->getDisplay());
        self::assertSame([500, 500], array_column($this->store->callsOf('list'), 'limit'));
    }

    public function testAllDrainsInTheConfiguredBatchSize(): void
    {
        foreach (range(1, 3) as $i) {
            $this->store->add(DeadLetterFactory::make("dl-{$i}", failedAt: $i));
        }
        $tester = $this->tester(batchSize: 2);

        $tester->execute([
            '--all' => true,
            '--force' => true,
        ]);

        self::assertStringContainsString('Replayed 3 dead letters', $tester->getDisplay());
        self::assertSame([2, 2, 2], array_column($this->store->callsOf('list'), 'limit'));
    }

    private function tester(?int $batchSize = null): CommandTester
    {
        $replay = new ReplayService($this->store, $this->outbox, new JsonWireFormat());

        return new CommandTester($batchSize === null
            ? new DlqReplayCommand($replay, $this->store)
            : new DlqReplayCommand($replay, $this->store, $batchSize));
    }
}
