<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Worker\WorkerLimits;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running consumer process. Consumers are constructed programmatically
 * (explicit composition, D9) and registered as callables. The command builds
 * one {@see WorkerLimits} from the options --limit, --time-limit,
 * --memory-limit and --failure-limit and gives it to the callable:
 *
 *     new ConsumeCommand([
 *         'orders' => static fn (WorkerLimits $limits) => $ordersConsumer->run($limits),
 *     ]);
 *
 * A limit stop ends the run like SIGTERM does, and the command exits with 0:
 * the supervisor must start a new process after each exit, exit code 0
 * included. A callable with no parameter still runs, with no limit.
 */
#[AsCommand(name: 'message-broker:consume', description: 'Run a registered consumer (blocks)')]
final class ConsumeCommand extends Command
{
    /** @param array<string, callable(WorkerLimits): void> $consumers keyed by consumer name */
    public function __construct(
        private readonly array $consumers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Registered consumer name');
        $this->addOption(
            'require-signals',
            null,
            InputOption::VALUE_NONE,
            'Fail if ext-pcntl (graceful shutdown) is unavailable',
        );
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many deliveries, 1 or more');
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds, 1 or more');
        $this->addOption(
            'memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop at this memory use, <number>[K|M|G] (for example 128M); keep it below the PHP memory_limit',
        );
        $this->addOption(
            'failure-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop after this many handler failures (retried, dead-lettered or discarded), 1 or more',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || !isset($this->consumers[$name])) {
            $known = implode(', ', array_keys($this->consumers));
            $output->writeln("<error>Unknown consumer. Registered consumers: {$known}</error>");

            return Command::INVALID;
        }

        try {
            $limits = new WorkerLimits(
                maxMessages: WorkerLimitOptions::intOption(WorkerLimitOptions::stringOption($input, 'limit'), 'limit'),
                maxSeconds: WorkerLimitOptions::intOption(WorkerLimitOptions::stringOption($input, 'time-limit'), 'time-limit'),
                maxMemoryBytes: WorkerLimitOptions::memoryOption(WorkerLimitOptions::stringOption($input, 'memory-limit')),
                maxFailures: WorkerLimitOptions::intOption(WorkerLimitOptions::stringOption($input, 'failure-limit'), 'failure-limit'),
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::INVALID;
        }

        $output->writeln("<info>Consuming '{$name}' — SIGTERM/SIGINT to stop.</info>");
        $warning = SignalSupport::warning(extension_loaded('pcntl'));
        if ($warning !== null) {
            if ($input->getOption('require-signals')) {
                $output->writeln("<error>{$warning}</error>");

                return Command::FAILURE;
            }
            $output->writeln("<comment>{$warning}</comment>");
        }

        $consumer = $this->consumers[$name];
        if ($limits != new WorkerLimits() && WorkerLimitOptions::takesNoParameter($consumer)) {
            $output->writeln(
                "<comment>The callable of consumer '{$name}' takes no WorkerLimits parameter, so the limit options have no effect.</comment>",
            );
        }
        $consumer($limits);

        return Command::SUCCESS;
    }
}
