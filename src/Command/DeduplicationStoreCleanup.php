<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'message-broker:deduplication-cleanup', description: 'Remove old idempotency records')]
final class DeduplicationStoreCleanup extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'message_broker_deduplication',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Remove records older than this many days', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daysOption = $input->getOption('days');
        $days = is_numeric($daysOption) ? (int) $daysOption : 30;

        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));

        $deleted = $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE processed_at < ?', $this->tableName),
            [$cutoff],
            [Types::DATETIME_IMMUTABLE],
        );

        $output->writeln("Removed {$deleted} old idempotency records");

        return Command::SUCCESS;
    }
}
