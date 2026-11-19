<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\DlqStatsCommand;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Tests\Fixtures\DeadLetterFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DlqStatsCommandTest extends TestCase
{
    private InMemoryDeadLetterStore $store;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->store = new InMemoryDeadLetterStore();
        $this->tester = new CommandTester(new DlqStatsCommand($this->store));
    }

    public function testTheCommandName(): void
    {
        self::assertSame('message-broker:dlq:stats', (new DlqStatsCommand($this->store))->getName());
    }

    public function testNoGroupPrintsTheEmptyText(): void
    {
        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("No dead letters match.\n", $this->tester->getDisplay());
    }

    public function testTheTableHasOneRowForEachGroup(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', failedAt: 1_750_000_000_123));
        $this->store->add(DeadLetterFactory::make('dl-2', failedAt: 1_750_000_002_005));
        $this->store->add(DeadLetterFactory::make('dl-3', messageName: 'order.cancelled', source: 'cancel_q', failedAt: 1_750_000_001_000));

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        $lines = explode("\n", $this->tester->getDisplay());
        self::assertMatchesRegularExpression(
            '/^\| message_name\s+\| source\s+\| error_class\s+\| count \| oldest_failed_at\s+\| newest_failed_at\s+\|$/',
            $lines[1],
        );
        self::assertMatchesRegularExpression(
            '/^\| order\.placed\s+\| orders_q \| RuntimeException \| 2\s+\| 2025-06-15 15:06:40\.123 \| 2025-06-15 15:06:42\.005 \|$/',
            $lines[3],
        );
        self::assertMatchesRegularExpression(
            '/^\| order\.cancelled \| cancel_q \| RuntimeException \| 1\s+\| 2025-06-15 15:06:41\.000 \| 2025-06-15 15:06:41\.000 \|$/',
            $lines[4],
        );
    }

    public function testTheFilterOptionsReachTheStore(): void
    {
        $this->tester->execute([
            '--source' => 'orders_q',
            '--not-replayed' => true,
        ]);

        self::assertEquals([[
            'filter' => new DeadLetterFilter(source: 'orders_q', replayed: false),
        ]], $this->store->callsOf('stats'));
    }

    public function testNoOptionAsksForAllRows(): void
    {
        $this->tester->execute([]);

        self::assertEquals([[
            'filter' => new DeadLetterFilter(),
        ]], $this->store->callsOf('stats'));
    }

    public function testAnInvalidOptionIsInvalidAndDoesNotCallTheStore(): void
    {
        $status = $this->tester->execute([
            '--replayed' => true,
            '--not-replayed' => true,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("Use --replayed or --not-replayed, not both.\n", $this->tester->getDisplay());
        self::assertSame([], $this->store->callsOf('stats'));
    }
}
