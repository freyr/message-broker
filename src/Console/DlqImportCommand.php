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
 * Imports the messages of a dead letter queue of the transport into the
 * dead_letters table. A batch command (for example from a Kubernetes CronJob): it ends when
 * the queue is empty or the limit is reached. Imports are constructed
 * programmatically and registered as callables:
 *
 *     new DlqImportCommand([
 *         'orders_q.dlq' => fn (?int $limit): int => $ordersImport->run($limit),
 *     ]);
 *
 * An error of the callable propagates, and the command exits with a non-zero
 * code.
 */
#[AsCommand(
    name: 'message-broker:dlq:import',
    description: 'Import the messages of a dead letter queue of the transport into the dead_letters table',
)]
final class DlqImportCommand extends Command
{
    /** @param array<string, callable(?int): int> $importers keyed by import name; each returns the imported count */
    public function __construct(
        private readonly array $importers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Registered import name');
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'The maximum number of messages in this run (a positive integer); no limit: until the queue is empty',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || !isset($this->importers[$name])) {
            $known = implode(', ', array_keys($this->importers));
            $output->writeln("<error>Unknown import. Registered imports: {$known}</error>");

            return Command::INVALID;
        }

        $limit = $input->getOption('limit');
        if ($limit !== null) {
            if (!is_string($limit) || preg_match('/\A[1-9][0-9]*\z/', $limit) !== 1) {
                $output->writeln('<error>--limit must be a positive integer</error>');

                return Command::INVALID;
            }
            $limit = (int) $limit;
        }

        $imported = ($this->importers[$name])($limit);
        $output->writeln("Imported {$imported} dead letters from '{$name}'.");

        return Command::SUCCESS;
    }
}
