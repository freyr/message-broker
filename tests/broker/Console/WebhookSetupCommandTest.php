<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\WebhookSetupCommand;
use Freyr\MessageBroker\Tests\Fixtures\RecordingSchemaInstaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The command depends on the SchemaInstaller port only: no PDO, no PostgreSQL. */
final class WebhookSetupCommandTest extends TestCase
{
    public function testTheNameIsTheWebhookSetupCommand(): void
    {
        self::assertSame('message-broker:webhook:setup', (new WebhookSetupCommand(new RecordingSchemaInstaller()))->getName());
    }

    public function testExecuteInstallsWithThePremakeDays(): void
    {
        $installer = new RecordingSchemaInstaller();
        $tester = new CommandTester(new WebhookSetupCommand($installer));

        $status = $tester->execute([
            '--premake-days' => '5',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([5], $installer->installCalls);
        self::assertSame([], $installer->sqlCalls);
        self::assertSame("The webhook tables and the partitions of webhook_deliveries for today + 5 days are present.\n", $tester->getDisplay());
    }

    public function testExecuteInstallsThreeDaysAheadByDefault(): void
    {
        $installer = new RecordingSchemaInstaller();

        (new CommandTester(new WebhookSetupCommand($installer)))->execute([]);

        self::assertSame([3], $installer->installCalls);
    }

    public function testZeroPremakeDaysIsValid(): void
    {
        $installer = new RecordingSchemaInstaller();

        $status = (new CommandTester(new WebhookSetupCommand($installer)))->execute([
            '--premake-days' => '0',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([0], $installer->installCalls);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPremakeDays(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'text' => ['many'];
        yield 'fraction' => ['1.5'];
        yield 'empty' => [''];
        yield 'leading zero' => ['03'];
        yield 'trailing newline' => ["3\n"];
    }

    #[DataProvider('invalidPremakeDays')]
    public function testAnInvalidPremakeDaysIsRejectedBeforeAnyCall(string $value): void
    {
        $installer = new RecordingSchemaInstaller();
        $tester = new CommandTester(new WebhookSetupCommand($installer));

        $status = $tester->execute([
            '--premake-days' => $value,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("--premake-days must be an integer of 0 or more.\n", $tester->getDisplay());
        self::assertSame([], $installer->installCalls);
        self::assertSame([], $installer->sqlCalls);
    }
}
