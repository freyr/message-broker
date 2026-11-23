<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\DlqImportCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DlqImportCommandTest extends TestCase
{
    /** @var list<int|null> the limits that reached the import callable */
    private array $limits = [];

    private function tester(int $imported = 3): CommandTester
    {
        return new CommandTester(new DlqImportCommand([
            'orders_q.dlq' => function (?int $limit) use ($imported): int {
                $this->limits[] = $limit;

                return $imported;
            },
            'payments_q.dlq' => static fn (?int $limit): int => 0,
        ]));
    }

    public function testTheCommandHasItsName(): void
    {
        self::assertSame('message-broker:dlq:import', (new DlqImportCommand([]))->getName());
    }

    public function testAnImportWithNoLimitPassesNullAndPrintsTheCount(): void
    {
        $tester = $this->tester(imported: 7);

        $status = $tester->execute([
            'name' => 'orders_q.dlq',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([null], $this->limits);
        self::assertSame("Imported 7 dead letters from 'orders_q.dlq'.\n", $tester->getDisplay());
    }

    public function testTheLimitReachesTheCallableAsAnInteger(): void
    {
        $tester = $this->tester(imported: 2);

        $status = $tester->execute([
            'name' => 'orders_q.dlq',
            '--limit' => '1000',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([1000], $this->limits);
        self::assertSame("Imported 2 dead letters from 'orders_q.dlq'.\n", $tester->getDisplay());
    }

    public function testALimitOfOneIsValid(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'orders_q.dlq',
            '--limit' => '1',
        ]));
        self::assertSame([1], $this->limits);
    }

    public function testAnUnknownNameIsInvalidAndListsTheRegisteredNames(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            'name' => 'nope',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("Unknown import. Registered imports: orders_q.dlq, payments_q.dlq\n", $tester->getDisplay());
        self::assertSame([], $this->limits);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'text' => ['ten'];
        yield 'leading zero' => ['010'];
        yield 'decimal' => ['1.5'];
        yield 'empty' => [''];
        yield 'number then text' => ['5x'];
        yield 'text then number' => ['x5'];
        yield 'trailing newline' => ["5\n"];
    }

    #[DataProvider('invalidLimits')]
    public function testALimitThatIsNotAPositiveIntegerIsInvalid(string $limit): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            'name' => 'orders_q.dlq',
            '--limit' => $limit,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("--limit must be a positive integer\n", $tester->getDisplay());
        self::assertSame([], $this->limits, 'the import does not run');
    }

    public function testAnErrorOfTheCallablePropagates(): void
    {
        $error = new RuntimeException('database down');
        $tester = new CommandTester(new DlqImportCommand([
            'orders_q.dlq' => static fn (?int $limit): int => throw $error,
        ]));

        try {
            $tester->execute([
                'name' => 'orders_q.dlq',
            ]);
            self::fail('the error of the import must propagate');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e);
        }
    }
}
