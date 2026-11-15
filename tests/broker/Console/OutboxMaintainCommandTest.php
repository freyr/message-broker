<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use DateTimeImmutable;
use Freyr\MessageBroker\Console\OutboxMaintainCommand;
use Freyr\MessageBroker\Outbox\RetentionReport;
use Freyr\MessageBroker\Tests\Fixtures\RecordingOutboxRetention;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The command depends on the OutboxRetention port only: no PDO, no PostgreSQL. */
final class OutboxMaintainCommandTest extends TestCase
{
    public function testDefaultsEnsureThreeDaysAndPurgeSevenDays(): void
    {
        $retention = new RecordingOutboxRetention();
        $tester = new CommandTester(new OutboxMaintainCommand($retention));

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([3], $retention->ensureCalls);
        self::assertSame([[7, false, false]], $retention->purgeCalls);
        self::assertStringContainsString('Daily partitions present: unit-a, unit-b.', $tester->getDisplay());
        self::assertStringContainsString('Retention cutoff: 2026-03-03 00:00 UTC.', $tester->getDisplay());
    }

    public function testOptionsReachThePort(): void
    {
        $retention = new RecordingOutboxRetention();

        (new CommandTester(new OutboxMaintainCommand($retention)))->execute([
            '--retention-days' => '2',
            '--premake-days' => '0',
            '--drop-unpublished' => true,
        ]);

        self::assertSame([0], $retention->ensureCalls);
        self::assertSame([[2, false, true]], $retention->purgeCalls);
    }

    public function testDryRunCreatesNothingAndAlwaysSucceeds(): void
    {
        $retention = new RecordingOutboxRetention(new RetentionReport(
            new DateTimeImmutable('2026-03-03 00:00:00 UTC'),
            ['unit-old'],
            [],
            [
                'unit-kept' => 4,
            ],
        ));
        $tester = new CommandTester(new OutboxMaintainCommand($retention));

        $status = $tester->execute([
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $retention->ensureCalls);
        self::assertSame([[7, true, false]], $retention->purgeCalls);
        self::assertStringContainsString('[dry-run] would ensure daily partitions for today + 3 days.', $tester->getDisplay());
        self::assertStringContainsString('[dry-run] Dropped unit-old.', $tester->getDisplay());
        self::assertStringContainsString('[dry-run] unit-kept would be kept: it holds 4 unpublished row(s)', $tester->getDisplay());
    }

    public function testAKeptUnitMakesTheCommandFail(): void
    {
        $retention = new RecordingOutboxRetention(new RetentionReport(
            new DateTimeImmutable('2026-03-03 00:00:00 UTC'),
            [],
            [],
            [
                'unit-kept' => 2,
            ],
        ));
        $tester = new CommandTester(new OutboxMaintainCommand($retention));

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('unit-kept is kept: it holds 2 unpublished row(s)', $tester->getDisplay());
    }

    public function testDroppedUnpublishedRowsAreReportedAsAWarning(): void
    {
        $retention = new RecordingOutboxRetention(new RetentionReport(
            new DateTimeImmutable('2026-03-03 00:00:00 UTC'),
            ['unit-old'],
            [
                'unit-old' => 3,
            ],
        ));
        $tester = new CommandTester(new OutboxMaintainCommand($retention));

        $status = $tester->execute([
            '--drop-unpublished' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Dropped unit-old.', $tester->getDisplay());
        self::assertStringContainsString('unit-old held 3 unpublished row(s) older than the retention period; they are dropped.', $tester->getDisplay());
    }

    public function testInvalidOptionsAreRejectedBeforeAnyCall(): void
    {
        $retention = new RecordingOutboxRetention();
        $tester = new CommandTester(new OutboxMaintainCommand($retention));

        $status = $tester->execute([
            '--retention-days' => '0',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame([], $retention->ensureCalls);
        self::assertSame([], $retention->purgeCalls);
    }
}
