<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'message-broker:deduplication-cleanup', description: 'Remove old idempotency records')]
class DeduplicationStoreCleanup extends Command
{
    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Remove records older than this many days', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) $input->getOption('days');

        $deleted = $this->connection->executeStatement(
            'DELETE FROM deduplication_store 
             WHERE processed_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );

        $output->writeln("Removed {$deleted} old idempotency records");

        return Command::SUCCESS;
    }
}
