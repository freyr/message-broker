<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\SetupSchemaCommand;
use Freyr\MessageBroker\Tests\Fixtures\RecordingSchemaInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The command depends on the SchemaInstaller port only: no PDO, no PostgreSQL. */
final class SetupSchemaCommandTest extends TestCase
{
    public function testExecuteInstallsWithThePremakeDays(): void
    {
        $installer = new RecordingSchemaInstaller();
        $tester = new CommandTester(new SetupSchemaCommand($installer));

        $status = $tester->execute([
            '--premake-days' => '5',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([5], $installer->installCalls);
        self::assertSame([], $installer->sqlCalls);
        self::assertSame("Schema is up to date.\n", $tester->getDisplay());
    }

    public function testExecuteInstallsThreeDaysAheadByDefault(): void
    {
        $installer = new RecordingSchemaInstaller();

        (new CommandTester(new SetupSchemaCommand($installer)))->execute([]);

        self::assertSame([3], $installer->installCalls);
    }

    public function testDumpSqlPrintsTheStatementsOfTheInstallerInOrderAndInstallsNothing(): void
    {
        $installer = new RecordingSchemaInstaller();
        $tester = new CommandTester(new SetupSchemaCommand($installer));

        $status = $tester->execute([
            '--dump-sql' => true,
            '--premake-days' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("CREATE TABLE one (id INT);\nCREATE TABLE two (days INT DEFAULT 2);\n", $tester->getDisplay());
        self::assertSame([2], $installer->sqlCalls);
        self::assertSame([], $installer->installCalls);
    }

    public function testInvalidPremakeDaysIsRejectedBeforeAnyCall(): void
    {
        $installer = new RecordingSchemaInstaller();
        $tester = new CommandTester(new SetupSchemaCommand($installer));

        $status = $tester->execute([
            '--premake-days' => '-1',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--premake-days must be an integer of 0 or more.', $tester->getDisplay());
        self::assertSame([], $installer->installCalls);
        self::assertSame([], $installer->sqlCalls);
    }
}
