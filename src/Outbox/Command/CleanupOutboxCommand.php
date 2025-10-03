<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Command;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cleanup Outbox Command.
 *
 * Optional maintenance command to remove processed outbox messages older than retention period.
 *
 * Note: This is a housekeeping tool - Symfony Messenger marks messages as delivered but doesn't
 * auto-delete them. Use this command periodically to prevent table growth.
 *
 * Failed messages are stored in messenger_messages table separately and managed by Symfony.
 */
#[AsCommand(
    name: 'messenger:cleanup-outbox',
    description: 'Clean up processed outbox messages older than retention period',
)]
final class CleanupOutboxCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'messenger_outbox',
        private readonly string $queueName = 'outbox',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Retention period in days (default: 7)',
                '7'
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Batch size for deletion (default: 1000)',
                '1000'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var numeric-string $daysOption */
        $daysOption = $input->getOption('days');
        $days = (int) $daysOption;
        /** @var numeric-string $batchSizeOption */
        $batchSizeOption = $input->getOption('batch-size');
        $batchSize = (int) $batchSizeOption;

        $io->title(sprintf('Cleaning up outbox messages older than %d days', $days));

        $cutoffDate = CarbonImmutable::now()->subDays($days);

        $io->info(sprintf('Cutoff date: %s', $cutoffDate->toDateTimeString()));
        $io->info(sprintf('Table: %s, Queue: %s', $this->tableName, $this->queueName));

        $totalDeleted = 0;

        do {
            // Delete delivered messages older than cutoff date
            // delivered_at IS NOT NULL means message was successfully processed
            $deletedCount = $this->connection->executeStatement(
                sprintf(
                    'DELETE FROM %s WHERE queue_name = :queue AND delivered_at IS NOT NULL AND delivered_at < :cutoff LIMIT :limit',
                    $this->tableName
                ),
                [
                    'queue' => $this->queueName,
                    'cutoff' => $cutoffDate->format('Y-m-d H:i:s'),
                    'limit' => $batchSize,
                ],
                [
                    'limit' => \PDO::PARAM_INT,
                ]
            );

            $totalDeleted += $deletedCount;

            if ($deletedCount > 0) {
                $io->writeln(sprintf('Deleted batch of %d messages (total: %d)', $deletedCount, $totalDeleted));
            }
        } while ($deletedCount === $batchSize);

        if ($totalDeleted > 0) {
            $io->success(sprintf('Successfully deleted %d processed outbox messages', $totalDeleted));
        } else {
            $io->info('No outbox messages to clean up');
        }

        return Command::SUCCESS;
    }
}
