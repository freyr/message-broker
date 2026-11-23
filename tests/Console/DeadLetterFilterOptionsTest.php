<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\DeadLetterFilterOptions;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;

final class DeadLetterFilterOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-06-15 12:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testConfigureAddsEachFilterOption(): void
    {
        $command = new Command('test');

        DeadLetterFilterOptions::configure($command);

        $options = $command->getDefinition()->getOptions();
        self::assertSame(
            ['name', 'source', 'error', 'message-id', 'since', 'until', 'min-attempts', 'replayed', 'not-replayed', 'replayable', 'not-replayable'],
            array_keys($options),
        );
        foreach (['name', 'source', 'error', 'message-id', 'since', 'until', 'min-attempts'] as $name) {
            self::assertTrue($options[$name]->isValueRequired(), "--{$name} takes a value");
            self::assertNull($options[$name]->getDefault(), "--{$name} has no default");
        }
        foreach (['replayed', 'not-replayed', 'replayable', 'not-replayable'] as $name) {
            self::assertFalse($options[$name]->acceptValue(), "--{$name} is a flag");
        }
    }

    public function testNoOptionGivesAnEmptyFilter(): void
    {
        self::assertEquals(new DeadLetterFilter(), $this->filter([]));
    }

    public function testEachOptionSetsItsField(): void
    {
        $filter = $this->filter([
            '--name' => 'order.placed',
            '--source' => 'orders_q',
            '--error' => 'App\\PaymentFailed',
            '--message-id' => 'm-1',
            '--since' => '2h',
            '--until' => '30m',
            '--min-attempts' => '3',
            '--replayed' => true,
            '--replayable' => true,
        ]);

        self::assertEquals(new DeadLetterFilter(
            messageName: 'order.placed',
            source: 'orders_q',
            errorClass: 'App\\PaymentFailed',
            messageId: 'm-1',
            failedFromMs: EpochMillis::now() - 7_200_000,
            failedUntilMs: EpochMillis::now() - 1_800_000,
            minAttempts: 3,
            replayed: true,
            replayable: true,
        ), $filter);
    }

    public function testTheNegativeFlagsSetFalse(): void
    {
        $filter = $this->filter([
            '--not-replayed' => true,
            '--not-replayable' => true,
        ]);

        self::assertFalse($filter->replayed);
        self::assertFalse($filter->replayable);
    }

    /** @return iterable<string, array{string, int}> */
    public static function dateTimes(): iterable
    {
        yield 'Z' => ['2026-06-10T08:30:00Z', 1_781_080_200_000];
        yield 'offset' => ['2026-06-10T10:30:00+02:00', 1_781_080_200_000];
        yield 'milliseconds' => ['2026-06-10T08:30:00.250Z', 1_781_080_200_250];
        yield 'no seconds' => ['2026-06-10T08:30Z', 1_781_080_200_000];
    }

    #[DataProvider('dateTimes')]
    public function testSinceAndUntilTakeAnIso8601DateTimeWithAnOffset(string $value, int $epochMs): void
    {
        self::assertSame($epochMs, $this->filter([
            '--since' => $value,
        ])->failedFromMs);
        self::assertSame($epochMs, $this->filter([
            '--until' => $value,
        ])->failedUntilMs);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'both replayed flags' => [[
            '--replayed' => true,
            '--not-replayed' => true,
        ], 'Use --replayed or --not-replayed, not both.'];
        yield 'both replayable flags' => [[
            '--replayable' => true,
            '--not-replayable' => true,
        ], 'Use --replayable or --not-replayable, not both.'];
        yield 'bad duration' => [[
            '--since' => '5x',
        ], "Invalid time '5x' for --since: expected a duration (30s, 15m, 24h, 7d) or an ISO 8601 date-time with an offset or Z"];
        yield 'date-time with no offset' => [[
            '--until' => '2026-06-10T08:30:00',
        ], "Invalid time '2026-06-10T08:30:00' for --until: expected a duration (30s, 15m, 24h, 7d) or an ISO 8601 date-time with an offset or Z"];
        yield 'date only' => [[
            '--since' => '2026-06-10',
        ], "Invalid time '2026-06-10' for --since: expected a duration (30s, 15m, 24h, 7d) or an ISO 8601 date-time with an offset or Z"];
        yield 'date-time with a trailing newline' => [[
            '--since' => "2026-06-10T08:30:00Z\n",
        ], "Invalid time '2026-06-10T08:30:00Z\n' for --since: expected a duration (30s, 15m, 24h, 7d) or an ISO 8601 date-time with an offset or Z"];
        yield 'impossible date' => [[
            '--since' => '2026-02-30T08:30:00Z',
        ], "Invalid time '2026-02-30T08:30:00Z' for --since: expected a duration (30s, 15m, 24h, 7d) or an ISO 8601 date-time with an offset or Z"];
        yield 'min attempts 0' => [[
            '--min-attempts' => '0',
        ], "Invalid --min-attempts '0': expected an integer of 1 or more"];
        yield 'min attempts not an integer' => [[
            '--min-attempts' => '2.5',
        ], "Invalid --min-attempts '2.5': expected an integer of 1 or more"];
        yield 'min attempts with a trailing newline' => [[
            '--min-attempts' => "3\n",
        ], "Invalid --min-attempts '3\n': expected an integer of 1 or more"];
        yield 'since not before until' => [[
            '--since' => '1h',
            '--until' => '2h',
        ], 'failedFromMs (1781521200000) must be lower than failedUntilMs (1781517600000)'];
        yield 'date-time before 1970' => [[
            '--since' => '1969-12-31T23:59:59.5Z',
        ], "Invalid time '1969-12-31T23:59:59.5Z' for --since: the time is before 1970-01-01T00:00:00Z"];
        yield 'year typo before 1970' => [[
            '--until' => '1026-06-10T08:30:00Z',
        ], "Invalid time '1026-06-10T08:30:00Z' for --until: the time is before 1970-01-01T00:00:00Z"];
        yield 'duration longer than the time since 1970' => [[
            '--since' => '20700d',
        ], "Invalid time '20700d' for --since: the time is before 1970-01-01T00:00:00Z"];
        yield 'largest duration that does not overflow' => [[
            '--until' => '106751991167d',
        ], "Invalid time '106751991167d' for --until: the time is before 1970-01-01T00:00:00Z"];
        yield 'smallest duration that overflows' => [[
            '--since' => '106751991168d',
        ], "Invalid time '106751991168d' for --since: the duration is too large"];
        yield 'duration with more digits than an integer' => [[
            '--since' => '99999999999999999999s',
        ], "Invalid time '99999999999999999999s' for --since: the duration is too large"];
        yield 'duration with a trailing newline' => [[
            '--since' => "24h\n",
        ], "Invalid time '24h\n' for --since: expected a duration (30s, 15m, 24h, 7d) or an ISO 8601 date-time with an offset or Z"];
    }

    public function testTheEpochIsTheEarliestValidTime(): void
    {
        self::assertSame(0, $this->filter([
            '--since' => '1970-01-01T00:00:00Z',
        ])->failedFromMs);
        self::assertSame(0, $this->filter([
            '--until' => intdiv(EpochMillis::now(), 1000).'s',
        ])->failedUntilMs, 'a duration back to the epoch');
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidInputs')]
    public function testAnInvalidInputThrows(array $input, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->filter($input);
    }

    public function testAMinAttemptsOfOneIsValid(): void
    {
        self::assertSame(1, $this->filter([
            '--min-attempts' => '1',
        ])->minAttempts);
    }

    public function testConfigureKeepsTheOtherOptionsOfTheCommand(): void
    {
        $command = new Command('test');
        $command->addOption('limit', null, InputOption::VALUE_REQUIRED);

        DeadLetterFilterOptions::configure($command);

        self::assertTrue($command->getDefinition()->hasOption('limit'));
        self::assertTrue($command->getDefinition()->hasOption('name'));
    }

    /** @param array<string, mixed> $options */
    private function filter(array $options): DeadLetterFilter
    {
        $command = new Command('test');
        DeadLetterFilterOptions::configure($command);

        return DeadLetterFilterOptions::filter(new ArrayInput($options, $command->getDefinition()));
    }
}
