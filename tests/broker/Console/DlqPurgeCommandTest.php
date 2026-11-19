<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\DlqPurgeCommand;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Tests\Fixtures\DeadLetterFactory;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

final class DlqPurgeCommandTest extends TestCase
{
    private InMemoryDeadLetterStore $store;
    private CommandTester $tester;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-06-15 12:00:00 UTC'));
        $this->store = new InMemoryDeadLetterStore();
        $this->store->add(DeadLetterFactory::make('dl-1', source: 'orders_q'));
        $this->store->add(DeadLetterFactory::make('dl-2', source: 'billing_q'));
        $this->store->add(DeadLetterFactory::make('dl-3', source: 'orders_q', messageName: 'order.cancelled'));
        $command = new DlqPurgeCommand($this->store);
        $command->setHelperSet(new HelperSet([new QuestionHelper()]));
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testDryRunReportsTheMatchingCountAndPurgesNothing(): void
    {
        $status = $this->tester->execute([
            '--dry-run' => true,
            '--source' => 'orders_q',
        ], [
            'interactive' => false,
        ]);

        self::assertSame(0, $status);
        self::assertSame("[dry-run] would delete up to 2 dead letters.\n", $this->tester->getDisplay());
        self::assertSame([], $this->store->callsOf('purge'));
        self::assertEquals([[
            'filter' => new DeadLetterFilter(source: 'orders_q'),
        ]], $this->store->callsOf('count'));
    }

    public function testRefusesWithoutForceOnANonInteractiveTerminal(): void
    {
        $status = $this->tester->execute([], [
            'interactive' => false,
        ]);

        self::assertSame(1, $status);
        self::assertSame(
            "Refusing to purge without --force (no interactive terminal).\n",
            $this->tester->getDisplay(),
        );
        self::assertSame([], $this->store->callsOf('purge'));
    }

    public function testForcedPurgePassesAllFiltersAndReportsTheCount(): void
    {
        $status = $this->tester->execute([
            '--force' => true,
            '--name' => 'order.placed',
            '--source' => 'orders_q',
            '--older-than' => '30d',
        ], [
            'interactive' => false,
        ]);

        self::assertSame(0, $status);
        self::assertSame("Purged 1 dead letters.\n", $this->tester->getDisplay());
        self::assertSame(2, $this->store->count(new DeadLetterFilter()), 'only dl-1 matches the three filters');
        self::assertEquals([[
            'filter' => new DeadLetterFilter(
                messageName: 'order.placed',
                source: 'orders_q',
                failedUntilMs: EpochMillis::now() - 30 * 86_400_000,
            ),
        ]], $this->store->callsOf('purge'));
    }

    public function testForcedPurgeWithoutFiltersPassesNone(): void
    {
        $this->tester->execute([
            '--force' => true,
        ], [
            'interactive' => false,
        ]);

        self::assertEquals([[
            'filter' => new DeadLetterFilter(),
        ]], $this->store->callsOf('purge'));
    }

    public function testInteractiveAnswerYesPurges(): void
    {
        $this->tester->setInputs(['y']);

        $status = $this->tester->execute([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('Delete up to 3 dead letters? [y/N]', $this->tester->getDisplay());
        self::assertCount(1, $this->store->callsOf('purge'));
    }

    public function testInteractiveDefaultAnswerDoesNotPurge(): void
    {
        $this->tester->setInputs(['']);

        $status = $this->tester->execute([]);

        self::assertSame(1, $status);
        self::assertSame([], $this->store->callsOf('purge'));
    }
}
