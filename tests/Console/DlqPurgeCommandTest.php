<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\DlqPurgeCommand;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Tests\Fixtures\DeadLetterFactory;
use Freyr\MessageBroker\Time\EpochMillis;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame("[dry-run] would delete 2 dead letters.\n", $this->tester->getDisplay());
        self::assertSame([], $this->store->callsOf('purge'));
        self::assertEquals([[
            'filter' => new DeadLetterFilter(source: 'orders_q'),
        ]], $this->store->callsOf('count'));
    }

    public function testTheDryRunCountAppliesOlderThan(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-old', failedAt: EpochMillis::now() - 31 * 86_400_000));
        $this->store->add(DeadLetterFactory::make('dl-recent', failedAt: EpochMillis::now() - 29 * 86_400_000));

        $status = $this->tester->execute([
            '--dry-run' => true,
            '--older-than' => '30d',
        ], [
            'interactive' => false,
        ]);

        self::assertSame(0, $status);
        self::assertSame("[dry-run] would delete 4 dead letters.\n", $this->tester->getDisplay(), 'dl-old and the three rows of 2025');
        self::assertEquals([[
            'filter' => new DeadLetterFilter(failedUntilMs: EpochMillis::now() - 30 * 86_400_000),
        ]], $this->store->callsOf('count'));
    }

    public function testTheFilterOptionsReachTheCountAndThePurge(): void
    {
        $this->tester->execute([
            '--force' => true,
            '--error' => 'RuntimeException',
            '--replayed' => true,
            '--since' => '400d',
        ], [
            'interactive' => false,
        ]);

        $expected = new DeadLetterFilter(
            errorClass: 'RuntimeException',
            failedFromMs: EpochMillis::now() - 400 * 86_400_000,
            replayed: true,
        );
        self::assertEquals($expected, $this->store->callsOf('count')[0]['filter']);
        self::assertEquals($expected, $this->store->callsOf('purge')[0]['filter']);
    }

    public function testOlderThanWithUntilIsInvalid(): void
    {
        $status = $this->tester->execute([
            '--force' => true,
            '--older-than' => '30d',
            '--until' => '7d',
        ], [
            'interactive' => false,
        ]);

        self::assertSame(2, $status);
        self::assertSame("Use --older-than or --until, not both.\n", $this->tester->getDisplay());
        self::assertSame([], $this->store->callsOf('count'));
        self::assertSame([], $this->store->callsOf('purge'));
    }

    public function testAnInvalidFilterOptionIsInvalid(): void
    {
        $status = $this->tester->execute([
            '--force' => true,
            '--min-attempts' => '0',
        ], [
            'interactive' => false,
        ]);

        self::assertSame(2, $status);
        self::assertSame("Invalid --min-attempts '0': expected an integer of 1 or more\n", $this->tester->getDisplay());
        self::assertSame([], $this->store->callsOf('purge'));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function badTimes(): iterable
    {
        yield 'older-than that overflows' => [[
            '--older-than' => '106751991168d',
        ], "Invalid time '106751991168d' for --older-than: the duration is too large"];
        yield 'older-than before 1970' => [[
            '--older-than' => '99999d',
        ], "Invalid time '99999d' for --older-than: the time is before 1970-01-01T00:00:00Z"];
        yield 'older-than that is not a duration' => [[
            '--older-than' => '30days',
        ], "Invalid time '30days' for --older-than: expected a duration (30s, 15m, 24h, 7d)"];
        yield 'older-than with a trailing newline' => [[
            '--older-than' => "30d\n",
        ], "Invalid time '30d\n' for --older-than: expected a duration (30s, 15m, 24h, 7d)"];
        yield 'since that overflows' => [[
            '--since' => '106751991168d',
        ], "Invalid time '106751991168d' for --since: the duration is too large"];
    }

    /** @param array<string, string> $options */
    #[DataProvider('badTimes')]
    public function testABadTimeIsInvalidAndPurgesNothing(array $options, string $message): void
    {
        $status = $this->tester->execute([
            '--force' => true,
        ] + $options, [
            'interactive' => false,
        ]);

        self::assertSame(2, $status);
        self::assertSame("{$message}\n", $this->tester->getDisplay());
        self::assertSame([], $this->store->callsOf('count'));
        self::assertSame([], $this->store->callsOf('purge'));
        self::assertSame(3, $this->store->count(new DeadLetterFilter()), 'every row stays');
    }

    public function testOlderThanNotAfterSinceIsInvalid(): void
    {
        $status = $this->tester->execute([
            '--force' => true,
            '--since' => '7d',
            '--older-than' => '30d',
        ], [
            'interactive' => false,
        ]);

        self::assertSame(2, $status);
        self::assertSame([], $this->store->callsOf('purge'));
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
        self::assertStringContainsString('Delete 3 dead letters? [y/N]', $this->tester->getDisplay());
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
