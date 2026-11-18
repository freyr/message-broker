<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\RelayRunCommand;
use Freyr\MessageBroker\Worker\WorkerLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

final class RelayRunCommandTest extends TestCase
{
    /** @var list<WorkerLimits> */
    private array $received = [];

    public function testEachOptionFillsItsField(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            'lane' => 'orders',
            '--limit' => '5',
            '--time-limit' => '60',
            '--memory-limit' => '128M',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $this->received);
        self::assertSame(5, $this->received[0]->maxMessages);
        self::assertSame(60, $this->received[0]->maxSeconds);
        self::assertSame(134_217_728, $this->received[0]->maxMemoryBytes);
        self::assertNull($this->received[0]->maxFailures);
    }

    public function testEachOptionWorksAlone(): void
    {
        $this->tester()->execute([
            'lane' => 'orders',
            '--limit' => '7',
        ]);
        $this->tester()->execute([
            'lane' => 'orders',
            '--time-limit' => '9',
        ]);
        $this->tester()->execute([
            'lane' => 'orders',
            '--memory-limit' => '2k',
        ]);

        self::assertCount(3, $this->received);
        self::assertEquals(new WorkerLimits(maxMessages: 7), $this->received[0]);
        self::assertEquals(new WorkerLimits(maxSeconds: 9), $this->received[1]);
        self::assertEquals(new WorkerLimits(maxMemoryBytes: 2_048), $this->received[2]);
    }

    public function testNoOptionGivesNoLimit(): void
    {
        $status = $this->tester()->execute([
            'lane' => 'orders',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertEquals(new WorkerLimits(), $this->received[0]);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'limit zero' => [[
            '--limit' => '0',
        ], '--limit'];
        yield 'limit negative' => [[
            '--limit' => '-3',
        ], '--limit'];
        yield 'limit text' => [[
            '--limit' => 'many',
        ], '--limit'];
        yield 'limit fraction' => [[
            '--limit' => '1.5',
        ], '--limit'];
        yield 'time limit zero' => [[
            '--time-limit' => '0',
        ], '--time-limit'];
        yield 'time limit unit' => [[
            '--time-limit' => '10s',
        ], '--time-limit'];
        yield 'memory limit zero' => [[
            '--memory-limit' => '0',
        ], '--memory-limit'];
        yield 'memory limit unit' => [[
            '--memory-limit' => '12X',
        ], '--memory-limit'];
        yield 'memory limit empty' => [[
            '--memory-limit' => '',
        ], '--memory-limit'];
    }

    /** @param array<string, string> $options */
    #[DataProvider('invalidOptions')]
    public function testAnInvalidValueGivesExitCodeTwoAndTheCallableDoesNotRun(array $options, string $name): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            'lane' => 'orders',
        ] + $options);

        self::assertSame(Command::INVALID, $status);
        self::assertSame([], $this->received);
        self::assertStringContainsString("Invalid value for {$name}", $tester->getDisplay());
    }

    public function testThereIsNoFailureLimitOption(): void
    {
        $command = new RelayRunCommand([]);

        self::assertFalse($command->getDefinition()->hasOption('failure-limit'));
        self::assertTrue($command->getDefinition()->hasOption('limit'));
        self::assertTrue($command->getDefinition()->hasOption('time-limit'));
        self::assertTrue($command->getDefinition()->hasOption('memory-limit'));
        self::assertTrue($command->getDefinition()->hasOption('require-signals'));

        $this->expectException(InvalidOptionException::class);
        (new CommandTester($command))->execute([
            'lane' => 'orders',
            '--failure-limit' => '3',
        ]);
    }

    public function testACallableWithNoParameterRunsAndTheWarningShowsWhenALimitIsSet(): void
    {
        $ran = 0;
        $tester = new CommandTester(new RelayRunCommand([
            'orders' => static function () use (&$ran): void {
                ++$ran;
            },
        ]));

        $status = $tester->execute([
            'lane' => 'orders',
            '--limit' => '5',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $ran);
        self::assertStringContainsString(
            'The callable of lane \'orders\' takes no WorkerLimits parameter, so the limit options have no effect.',
            $tester->getDisplay(),
        );
    }

    public function testNoWarningWithNoLimitOrWithAParameter(): void
    {
        $ran = 0;
        $noParameter = new CommandTester(new RelayRunCommand([
            'orders' => static function () use (&$ran): void {
                ++$ran;
            },
        ]));
        $noParameter->execute([
            'lane' => 'orders',
        ]);

        $withParameter = $this->tester();
        $withParameter->execute([
            'lane' => 'orders',
            '--limit' => '5',
        ]);

        self::assertSame(1, $ran);
        self::assertStringNotContainsString('no WorkerLimits parameter', $noParameter->getDisplay());
        self::assertStringNotContainsString('no WorkerLimits parameter', $withParameter->getDisplay());
    }

    public function testTheExitCodeIsZeroAfterTheCallableReturned(): void
    {
        self::assertSame(0, $this->tester()->execute([
            'lane' => 'orders',
            '--limit' => '1',
        ]));
    }

    public function testAnUnknownLaneGivesExitCodeTwoAndListsTheLanes(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            'lane' => 'nope',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame([], $this->received);
        self::assertStringContainsString('Unknown lane. Registered lanes: orders', $tester->getDisplay());
    }

    public function testRequireSignalsRunsTheCallableWhenPcntlIsLoaded(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('ext-pcntl is not loaded');
        }
        $tester = $this->tester();

        $status = $tester->execute([
            'lane' => 'orders',
            '--require-signals' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $this->received);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new RelayRunCommand([
            'orders' => function (WorkerLimits $limits): void {
                $this->received[] = $limits;
            },
        ]));
    }
}
