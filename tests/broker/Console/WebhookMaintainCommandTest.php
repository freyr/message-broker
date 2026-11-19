<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use DateTimeImmutable;
use Freyr\MessageBroker\Console\WebhookMaintainCommand;
use Freyr\MessageBroker\Outbox\RetentionReport;
use Freyr\MessageBroker\Tests\Fixtures\RecordingDeliveryRetention;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The command depends on the DeliveryRetention port only: no PDO, no PostgreSQL. */
final class WebhookMaintainCommandTest extends TestCase
{
    public function testTheNameIsTheWebhookMaintainCommand(): void
    {
        self::assertSame('message-broker:webhook:maintain', (new WebhookMaintainCommand(new RecordingDeliveryRetention()))->getName());
    }

    public function testDefaultsEnsureThreeDaysAndPurgeSevenDays(): void
    {
        $retention = new RecordingDeliveryRetention();
        $tester = new CommandTester(new WebhookMaintainCommand($retention));

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([3], $retention->ensureCalls);
        self::assertSame([[7, false]], $retention->purgeCalls);
        self::assertSame(
            "Daily partitions present: unit-a, unit-b.\n"
            ."Retention cutoff: 2026-03-03 00:00 UTC.\n",
            $tester->getDisplay(),
        );
    }

    public function testOptionsReachThePort(): void
    {
        $retention = new RecordingDeliveryRetention();

        (new CommandTester(new WebhookMaintainCommand($retention)))->execute([
            '--retention-days' => '2',
            '--premake-days' => '0',
        ]);

        self::assertSame([0], $retention->ensureCalls);
        self::assertSame([[2, false]], $retention->purgeCalls);
    }

    public function testDroppedUnitsArePrinted(): void
    {
        $retention = new RecordingDeliveryRetention(new RetentionReport(
            new DateTimeImmutable('2026-03-03 00:00:00 UTC'),
            ['unit-old', 'unit-older'],
            [],
        ));
        $tester = new CommandTester(new WebhookMaintainCommand($retention));

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            "Daily partitions present: unit-a, unit-b.\n"
            ."Retention cutoff: 2026-03-03 00:00 UTC.\n"
            ."Dropped unit-old.\n"
            ."Dropped unit-older.\n",
            $tester->getDisplay(),
        );
    }

    public function testAKeptUnitMakesTheCommandFail(): void
    {
        $retention = new RecordingDeliveryRetention(new RetentionReport(
            new DateTimeImmutable('2026-03-03 00:00:00 UTC'),
            ['unit-old'],
            [],
            [
                'unit-kept' => 2,
            ],
        ));
        $tester = new CommandTester(new WebhookMaintainCommand($retention));

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            "Daily partitions present: unit-a, unit-b.\n"
            ."Retention cutoff: 2026-03-03 00:00 UTC.\n"
            ."Dropped unit-old.\n"
            .'unit-kept is kept: it holds 2 unfinished row(s) older than the retention period. '
            ."They wait for a final state; end old rows with message-broker:webhook:abandon --before=<date>.\n",
            $tester->getDisplay(),
        );
    }

    public function testDryRunCreatesNothingAndAlwaysSucceeds(): void
    {
        $retention = new RecordingDeliveryRetention(new RetentionReport(
            new DateTimeImmutable('2026-03-03 00:00:00 UTC'),
            ['unit-old'],
            [],
            [
                'unit-kept' => 4,
            ],
        ));
        $tester = new CommandTester(new WebhookMaintainCommand($retention));

        $status = $tester->execute([
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $retention->ensureCalls);
        self::assertSame([[7, true]], $retention->purgeCalls);
        self::assertSame(
            "[dry-run] would ensure daily partitions for today + 3 days.\n"
            ."[dry-run] Retention cutoff: 2026-03-03 00:00 UTC.\n"
            ."[dry-run] Dropped unit-old.\n"
            .'[dry-run] unit-kept would be kept: it holds 4 unfinished row(s) older than the retention period. '
            ."They wait for a final state; end old rows with message-broker:webhook:abandon --before=<date>.\n",
            $tester->getDisplay(),
        );
    }

    public function testThereIsNoOptionThatDropsUnfinishedRows(): void
    {
        $definition = (new WebhookMaintainCommand(new RecordingDeliveryRetention()))->getDefinition();

        self::assertSame(['retention-days', 'premake-days', 'dry-run'], array_keys($definition->getOptions()));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidOptions(): iterable
    {
        yield 'retention zero' => [[
            '--retention-days' => '0',
        ]];
        yield 'retention negative' => [[
            '--retention-days' => '-7',
        ]];
        yield 'retention text' => [[
            '--retention-days' => 'week',
        ]];
        yield 'retention trailing newline' => [[
            '--retention-days' => "7\n",
        ]];
        yield 'retention leading zero' => [[
            '--retention-days' => '07',
        ]];
        yield 'premake negative' => [[
            '--premake-days' => '-1',
        ]];
        yield 'premake fraction' => [[
            '--premake-days' => '1.5',
        ]];
        yield 'premake trailing newline' => [[
            '--premake-days' => "3\n",
        ]];
        yield 'premake leading zero' => [[
            '--premake-days' => '00',
        ]];
    }

    /** @param array<string, string> $options */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejectedBeforeAnyCall(array $options): void
    {
        $retention = new RecordingDeliveryRetention();
        $tester = new CommandTester(new WebhookMaintainCommand($retention));

        $status = $tester->execute($options);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("--retention-days must be an integer of 1 or more, --premake-days of 0 or more.\n", $tester->getDisplay());
        self::assertSame([], $retention->ensureCalls);
        self::assertSame([], $retention->purgeCalls);
    }
}
