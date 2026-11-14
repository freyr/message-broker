<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Console;

use Freyr\MessageBroker\Console\SchemaValidateCommand;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaValidateCommandTest extends TestCase
{
    private const string V1 = __DIR__.'/../../Fixtures/schemas/order.placed/v1.avsc';
    private const string V2 = __DIR__.'/../../Fixtures/schemas/order.placed/v2.avsc';

    public function testPassesWhenCurrentSchemaResolvesInTheVersionSet(): void
    {
        $tester = new CommandTester(new SchemaValidateCommand(
            new FileSchemaStore([
                'order.placed' => self::V2,
            ]),
            [self::V1, self::V2],
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('order.placed → ', $tester->getDisplay());
    }

    public function testFailsWhenCurrentSchemaIsNotInTheVersionSet(): void
    {
        // Producer's current schema is v2, but only v1 is committed to the set.
        $tester = new CommandTester(new SchemaValidateCommand(
            new FileSchemaStore([
                'order.placed' => self::V2,
            ]),
            [self::V1],
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('not in the committed all-versions set', $tester->getDisplay());
    }

    public function testFailsWhenTheCommittedSetIsUnreadable(): void
    {
        $tester = new CommandTester(new SchemaValidateCommand(
            new FileSchemaStore([
                'order.placed' => self::V1,
            ]),
            ['/nonexistent/v1.avsc'],
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Committed schema set is invalid', $tester->getDisplay());
    }

    public function testSucceedsWithNothingMapped(): void
    {
        $tester = new CommandTester(new SchemaValidateCommand(new FileSchemaStore([]), []));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('nothing to validate', $tester->getDisplay());
    }
}
