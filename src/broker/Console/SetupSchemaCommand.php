<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Storage\SchemaInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'message-broker:setup:schema',
    description: 'Create the outbox_messages (with its first daily partitions), message_deduplication and dead_letters tables',
)]
final class SetupSchemaCommand extends Command
{
    public function __construct(
        private readonly SchemaInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('premake-days', null, InputOption::VALUE_REQUIRED, 'Create daily outbox partitions this many days ahead', '3')
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the DDL instead of executing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $premakeOption = $input->getOption('premake-days');
        if (!is_string($premakeOption) || preg_match('/^\d+$/', $premakeOption) !== 1) {
            $output->writeln('<error>--premake-days must be an integer of 0 or more.</error>');

            return Command::INVALID;
        }

        $premakeDays = (int) $premakeOption;

        if ($input->getOption('dump-sql') === true) {
            foreach ($this->installer->sql($premakeDays) as $statement) {
                $output->writeln($statement.';');
            }

            return Command::SUCCESS;
        }

        $this->installer->install($premakeDays);
        $output->writeln('<info>Schema is up to date.</info>');

        return Command::SUCCESS;
    }
}
