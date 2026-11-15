<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running consumer process. Consumers are constructed programmatically
 * (explicit composition, D9) and registered as callables:
 *
 *     new ConsumeCommand([
 *         'orders' => fn () => $ordersConsumer->run(),
 *     ]);
 */
#[AsCommand(name: 'message-broker:consume', description: 'Run a registered consumer (blocks)')]
final class ConsumeCommand extends Command
{
    /** @param array<string, callable(): void> $consumers keyed by consumer name */
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || !isset($this->consumers[$name])) {
            $known = implode(', ', array_keys($this->consumers));
            $output->writeln("<error>Unknown consumer. Registered consumers: {$known}</error>");

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
        ($this->consumers[$name])();

        return Command::SUCCESS;
    }
}
