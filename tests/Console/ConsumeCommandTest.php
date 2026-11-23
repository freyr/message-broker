<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\ConsumeCommand;
use Freyr\MessageBroker\Worker\WorkerLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsumeCommandTest extends TestCase
{
    /** @var list<WorkerLimits> */
    private array $received = [];

    public function testEachOptionFillsItsField(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            'name' => 'orders',
            '--limit' => '5',
            '--time-limit' => '60',
            '--memory-limit' => '128M',
            '--failure-limit' => '3',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("Consuming 'orders' — SIGTERM/SIGINT to stop.", $tester->getDisplay());
        self::assertCount(1, $this->received);
        self::assertSame(5, $this->received[0]->maxMessages);
        self::assertSame(60, $this->received[0]->maxSeconds);
        self::assertSame(134_217_728, $this->received[0]->maxMemoryBytes);
        self::assertSame(3, $this->received[0]->maxFailures);
    }

    public function testEachOptionWorksAlone(): void
    {
        $this->tester()->execute([
            'name' => 'orders',
            '--limit' => '7',
        ]);
        $this->tester()->execute([
            'name' => 'orders',
            '--time-limit' => '9',
        ]);
        $this->tester()->execute([
            'name' => 'orders',
            '--memory-limit' => '2k',
        ]);
        $this->tester()->execute([
            'name' => 'orders',
            '--failure-limit' => '4',
        ]);

        self::assertCount(4, $this->received);
        self::assertEquals(new WorkerLimits(maxMessages: 7), $this->received[0]);
        self::assertEquals(new WorkerLimits(maxSeconds: 9), $this->received[1]);
        self::assertEquals(new WorkerLimits(maxMemoryBytes: 2_048), $this->received[2]);
        self::assertEquals(new WorkerLimits(maxFailures: 4), $this->received[3]);
    }

    public function testNoOptionGivesNoLimit(): void
    {
        $status = $this->tester()->execute([
            'name' => 'orders',
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
        yield 'limit trailing newline' => [[
            '--limit' => "5\n",
        ], '--limit'];
        yield 'time limit trailing newline' => [[
            '--time-limit' => "60\n",
        ], '--time-limit'];
        yield 'memory limit trailing newline' => [[
            '--memory-limit' => "128M\n",
        ], '--memory-limit'];
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
        yield 'memory limit overflow' => [[
            '--memory-limit' => '9000000000G',
        ], '--memory-limit'];
        yield 'failure limit zero' => [[
            '--failure-limit' => '0',
        ], '--failure-limit'];
        yield 'failure limit negative' => [[
            '--failure-limit' => '-1',
        ], '--failure-limit'];
        yield 'failure limit text' => [[
            '--failure-limit' => 'few',
        ], '--failure-limit'];
        yield 'failure limit trailing newline' => [[
            '--failure-limit' => "3\n",
        ], '--failure-limit'];
    }

    /** @param array<string, string> $options */
    #[DataProvider('invalidOptions')]
    public function testAnInvalidValueGivesExitCodeTwoAndTheCallableDoesNotRun(array $options, string $name): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            'name' => 'orders',
        ] + $options);

        self::assertSame(Command::INVALID, $status);
        self::assertSame([], $this->received);
        self::assertStringContainsString("Invalid value for {$name}", $tester->getDisplay());
    }

    public function testTheCommandHasTheFourLimitOptionsAndRequireSignals(): void
    {
        $definition = (new ConsumeCommand([]))->getDefinition();

        foreach (['limit', 'time-limit', 'memory-limit', 'failure-limit'] as $name) {
            self::assertTrue($definition->hasOption($name), $name);
            self::assertTrue($definition->getOption($name)->isValueRequired(), "{$name} takes a value");
        }
        self::assertTrue($definition->hasOption('require-signals'));
        self::assertFalse($definition->getOption('require-signals')->acceptValue());

        $this->expectException(InvalidOptionException::class);
        (new CommandTester(new ConsumeCommand([
            'orders' => static function (): void {},
        ])))->execute([
            'name' => 'orders',
            '--max-failures' => '3',
        ]);
    }

    public function testACallableWithNoParameterRunsAndTheWarningShowsWhenALimitIsSet(): void
    {
        $ran = 0;
        $tester = new CommandTester(new ConsumeCommand([
            'orders' => static function () use (&$ran): void {
                ++$ran;
            },
        ]));

        $status = $tester->execute([
            'name' => 'orders',
            '--limit' => '5',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $ran);
        self::assertStringContainsString(
            'The callable of consumer \'orders\' takes no WorkerLimits parameter, so the limit options have no effect.',
            $tester->getDisplay(),
        );
    }

    public function testNoWarningWithNoLimitOrWithAParameter(): void
    {
        $ran = 0;
        $noParameter = new CommandTester(new ConsumeCommand([
            'orders' => static function () use (&$ran): void {
                ++$ran;
            },
        ]));
        $noParameter->execute([
            'name' => 'orders',
        ]);

        $withParameter = $this->tester();
        $withParameter->execute([
            'name' => 'orders',
            '--limit' => '5',
        ]);

        self::assertSame(1, $ran);
        self::assertStringNotContainsString('no WorkerLimits parameter', $noParameter->getDisplay());
        self::assertStringNotContainsString('no WorkerLimits parameter', $withParameter->getDisplay());
    }

    public function testTheExitCodeIsZeroAfterTheCallableReturned(): void
    {
        self::assertSame(0, $this->tester()->execute([
            'name' => 'orders',
            '--limit' => '1',
        ]));
    }

    public function testAnUnknownConsumerGivesExitCodeTwoAndListsTheConsumers(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            'name' => 'nope',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame([], $this->received);
        self::assertStringContainsString('Unknown consumer. Registered consumers: orders', $tester->getDisplay());
    }

    public function testRequireSignalsRunsTheCallableWhenPcntlIsLoaded(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('ext-pcntl is not loaded');
        }
        $tester = $this->tester();

        $status = $tester->execute([
            'name' => 'orders',
            '--require-signals' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $this->received);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new ConsumeCommand([
            'orders' => function (WorkerLimits $limits): void {
                $this->received[] = $limits;
            },
        ]));
    }
}
