<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\DlqListCommand;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Tests\Fixtures\DeadLetterFactory;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;

final class DlqListCommandTest extends TestCase
{
    private InMemoryDeadLetterStore $store;
    private CommandTester $tester;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-06-15 12:00:00 UTC'));
        $this->store = new InMemoryDeadLetterStore();
        $this->tester = new CommandTester(new DlqListCommand($this->store));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testEmptyQueueSaysSo(): void
    {
        $status = $this->tester->execute([]);

        self::assertSame(0, $status);
        self::assertSame("Dead letter queue is empty.\n", $this->tester->getDisplay());
    }

    public function testWithoutOptionsAsksForTheFirstHundredUnfilteredRows(): void
    {
        $this->tester->execute([]);

        self::assertEquals([[
            'filter' => new DeadLetterFilter(),
            'limit' => 100,
            'offset' => 0,
        ]], $this->store->callsOf('list'));
    }

    public function testFilterOptionsReachTheStore(): void
    {
        $this->tester->execute([
            '--name' => 'order.placed',
            '--source' => 'orders_q',
            '--since' => '24h',
            '--limit' => '7',
            '--offset' => '3',
        ]);

        self::assertEquals([[
            'filter' => new DeadLetterFilter(
                messageName: 'order.placed',
                source: 'orders_q',
                failedFromMs: EpochMillis::now() - 86_400_000,
            ),
            'limit' => 7,
            'offset' => 3,
        ]], $this->store->callsOf('list'));
    }

    public function testNonNumericLimitAndOffsetFallBackToTheDefaults(): void
    {
        $this->tester->execute([
            '--limit' => 'many',
            '--offset' => 'some',
        ]);

        $call = $this->store->callsOf('list')[0];
        self::assertSame(100, $call['limit']);
        self::assertSame(0, $call['offset']);
    }

    public function testTableShowsEveryColumnOfEachDeadLetter(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-new', errorMessage: 'newer failure', failedAt: 1_750_000_002_005));
        $this->store->add(DeadLetterFactory::make(
            'dl-old',
            messageName: 'order.cancelled',
            source: 'cancel_q',
            failedAt: 1_750_000_000_123,
            replayedAt: 1_750_000_001_000,
        ));

        $this->tester->execute([]);

        $lines = explode("\n", $this->tester->getDisplay());
        self::assertMatchesRegularExpression(
            '/^\| id\s+\| message_name\s+\| source\s+\| attempts \| failed_at\s+\| replayed \| error\s+\|$/',
            $lines[1],
        );
        self::assertMatchesRegularExpression(
            '/^\| dl-new \| order\.placed\s+\| orders_q \| 5\s+\| 2025-06-15 15:06:42\.005 \| no\s+\| newer failure\s+\|$/',
            $lines[3],
        );
        self::assertMatchesRegularExpression(
            '/^\| dl-old \| order\.cancelled \| cancel_q \| 5\s+\| 2025-06-15 15:06:40\.123 \| yes\s+\| boom\s+\|$/',
            $lines[4],
        );
    }

    public function testErrorMessageOfExactlySixtyCharactersIsNotTruncated(): void
    {
        $message = str_repeat('a', 60);
        $this->store->add(DeadLetterFactory::make('dl-1', errorMessage: $message));

        $this->tester->execute([]);

        self::assertStringContainsString("| {$message} |", $this->tester->getDisplay());
    }

    public function testLongErrorMessageIsCutToFiftySevenCharactersAndEllipsis(): void
    {
        $message = implode('', array_map(static fn (int $i): string => chr(97 + $i % 26), range(0, 69)));
        $this->store->add(DeadLetterFactory::make('dl-1', errorMessage: $message));

        $this->tester->execute([]);

        self::assertStringContainsString('| '.substr($message, 0, 57).'... |', $this->tester->getDisplay());
    }

    public function testErrorMessageLengthIsCountedInCharactersNotBytes(): void
    {
        $short = str_repeat('ą', 40); // 40 characters, 80 bytes: below the limit
        $long = str_repeat('ę', 61);
        $this->store->add(DeadLetterFactory::make('dl-short', errorMessage: $short, failedAt: 2));
        $this->store->add(DeadLetterFactory::make('dl-long', errorMessage: $long, failedAt: 1));

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString("| {$short} ", $display);
        self::assertStringContainsString('| '.str_repeat('ę', 57).'... |', $display);
    }
}
