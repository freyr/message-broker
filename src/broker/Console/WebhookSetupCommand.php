<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Storage\SchemaInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates the webhook_deliveries table and its daily storage units from today
 * to today + --premake-days days. It takes the schema installer of the
 * webhook queue (the PostgreSQL adapter has one apart from the installer of
 * message-broker:setup:schema), so an application that does not use webhooks
 * runs no DDL for it. Safe to repeat.
 */
#[AsCommand(
    name: 'message-broker:webhook:setup',
    description: 'Create the webhook_deliveries table with its first daily partitions',
)]
final class WebhookSetupCommand extends Command
{
    public function __construct(
        private readonly SchemaInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('premake-days', null, InputOption::VALUE_REQUIRED, 'Create daily partitions this many days ahead', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $premakeOption = $input->getOption('premake-days');
        if (!is_string($premakeOption) || preg_match('/\A(0|[1-9]\d*)\z/', $premakeOption) !== 1) {
            $output->writeln('<error>--premake-days must be an integer of 0 or more.</error>');

            return Command::INVALID;
        }

        $premakeDays = (int) $premakeOption;
        $this->installer->install($premakeDays);
        $output->writeln("<info>The webhook_deliveries table and its partitions for today + {$premakeDays} days are present.</info>");

        return Command::SUCCESS;
    }
}
