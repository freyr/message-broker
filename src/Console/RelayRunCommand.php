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
 * Long-running relay worker for one lane; run as many workers per lane as
 * the throughput needs. Every relay is a {@see \Freyr\MessageBroker\Outbox\Relay}
 * over an OutboxStore and an OutboxPublisher; the application registers the
 * runners as callables. The command builds one {@see WorkerLimits} from the
 * options --limit, --time-limit and --memory-limit and gives it to the callable:
 *
 *     new RelayRunCommand([
 *         'orders' => static fn (WorkerLimits $limits) => $ordersRelay->run($limits),
 *         'invoices' => static fn (WorkerLimits $limits) => $invoicesRelay->run($limits),
 *     ]);
 *
 * A limit stop ends the run like SIGTERM does, and the command exits with 0:
 * the container must start again after each exit, exit code 0 included (a
 * Kubernetes Deployment, restartPolicy Always). A callable with no parameter
 * still runs, with no limit.
 */
#[AsCommand(name: 'message-broker:relay:run', description: 'Run the relay for one outbox lane (blocks)')]
final class RelayRunCommand extends Command
{
    /** @param array<string, callable(WorkerLimits): void> $relays keyed by lane */
    public function __construct(
        private readonly array $relays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('lane', InputArgument::REQUIRED, 'Outbox lane to drain');
        $this->addOption(
            'require-signals',
            null,
            InputOption::VALUE_NONE,
            'Fail if ext-pcntl (graceful shutdown) is unavailable',
        );
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop after this many published rows, 1 or more (checked after each batch)',
        );
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds, 1 or more');
        $this->addOption(
            'memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop at this memory use, <number>[K|M|G] (for example 128M); keep it below the PHP memory_limit',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lane = $input->getArgument('lane');
        if (!is_string($lane) || !isset($this->relays[$lane])) {
            $known = implode(', ', array_keys($this->relays));
            $output->writeln("<error>Unknown lane. Registered lanes: {$known}</error>");

            return Command::INVALID;
        }

        try {
            $limits = new WorkerLimits(
                maxMessages: WorkerLimitOptions::intOption(WorkerLimitOptions::stringOption($input, 'limit'), 'limit'),
                maxSeconds: WorkerLimitOptions::intOption(WorkerLimitOptions::stringOption($input, 'time-limit'), 'time-limit'),
                maxMemoryBytes: WorkerLimitOptions::memoryOption(WorkerLimitOptions::stringOption($input, 'memory-limit')),
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::INVALID;
        }

        $output->writeln("<info>Relaying lane '{$lane}' — SIGTERM/SIGINT to stop.</info>");
        $warning = SignalSupport::warning(extension_loaded('pcntl'));
        if ($warning !== null) {
            if ($input->getOption('require-signals')) {
                $output->writeln("<error>{$warning}</error>");

                return Command::FAILURE;
            }
            $output->writeln("<comment>{$warning}</comment>");
        }

        $relay = $this->relays[$lane];
        if ($limits != new WorkerLimits() && WorkerLimitOptions::takesNoParameter($relay)) {
            $output->writeln(
                "<comment>The callable of lane '{$lane}' takes no WorkerLimits parameter, so the limit options have no effect.</comment>",
            );
        }
        $relay($limits);

        return Command::SUCCESS;
    }
}
