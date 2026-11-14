<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Outbox\OutboxPartitionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Outbox retention, meant for a daily schedule: create the daily partitions
 * ahead of time, then drop expired partitions that hold only published rows.
 * Exits non-zero when an expired partition still holds unpublished rows, so a
 * stuck relay surfaces in the scheduler.
 */
#[AsCommand(
    name: 'message-broker:outbox:maintain',
    description: 'Create upcoming outbox partitions and drop expired, fully published ones',
)]
final class OutboxMaintainCommand extends Command
{
    public function __construct(
        private readonly OutboxPartitionManager $partitions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Keep published rows at least this many days', '7')
            ->addOption('premake-days', null, InputOption::VALUE_REQUIRED, 'Create daily partitions this many days ahead', '3')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionDays = self::positiveInt($input->getOption('retention-days'));
        $premakeDays = self::positiveInt($input->getOption('premake-days'));
        if ($retentionDays === null || $premakeDays === null) {
            $output->writeln('<error>--retention-days and --premake-days must be integers of 1 or more.</error>');

            return Command::INVALID;
        }
        $dryRun = $input->getOption('dry-run') === true;
        $prefix = $dryRun ? '[dry-run] ' : '';

        if ($dryRun) {
            $output->writeln("<info>[dry-run] would ensure daily partitions for today + {$premakeDays} days.</info>");
        } else {
            $created = $this->partitions->ensurePartitions($premakeDays);
            $output->writeln(sprintf('<info>Daily partitions present: %s.</info>', implode(', ', $created)));
        }

        $report = $this->partitions->purge($retentionDays, $dryRun);
        $output->writeln(sprintf('<info>%sRetention cutoff: %s UTC.</info>', $prefix, $report->cutoff->format('Y-m-d H:i')));
        foreach ($report->dropped as $name) {
            $output->writeln("<info>{$prefix}Dropped {$name}.</info>");
        }
        foreach ($report->kept as $name => $pending) {
            $output->writeln("<error>{$prefix}Kept {$name}: {$pending} unpublished row(s) — check the relay.</error>");
        }
        if ($report->defaultRowsDeleted > 0) {
            $output->writeln("<info>{$prefix}Deleted {$report->defaultRowsDeleted} expired published row(s) from the default partition.</info>");
        }
        if ($report->defaultRowsRemaining > 0) {
            $output->writeln(sprintf(
                '<comment>%s%d row(s) remain in %s — run this command before the day starts (raise --premake-days).</comment>',
                $prefix,
                $report->defaultRowsRemaining,
                OutboxPartitionManager::DEFAULT_PARTITION,
            ));
        }

        return $report->hasStuckRows() ? Command::FAILURE : Command::SUCCESS;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
