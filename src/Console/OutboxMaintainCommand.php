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
 * ahead of time, then drop the expired ones (DETACH ... CONCURRENTLY, then
 * DROP). The retention period is also the age limit of an unpublished row:
 * expired unpublished rows are dropped and reported as a warning.
 */
#[AsCommand(
    name: 'message-broker:outbox:maintain',
    description: 'Create upcoming outbox partitions and drop expired ones',
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
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Keep rows (published or not) at least this many days', '7')
            ->addOption('premake-days', null, InputOption::VALUE_REQUIRED, 'Create daily partitions this many days ahead', '3')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionDays = self::intOption($input->getOption('retention-days'));
        $premakeDays = self::intOption($input->getOption('premake-days'), allowZero: true);
        if ($retentionDays === null || $premakeDays === null) {
            $output->writeln('<error>--retention-days must be an integer of 1 or more, --premake-days of 0 or more.</error>');

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
        foreach ($report->unpublished as $name => $count) {
            $output->writeln("<comment>{$prefix}{$name} held {$count} unpublished row(s) older than the retention period; they are dropped.</comment>");
        }

        return Command::SUCCESS;
    }

    private static function intOption(mixed $value, bool $allowZero = false): ?int
    {
        $pattern = $allowZero ? '/^(0|[1-9]\d*)$/' : '/^[1-9]\d*$/';
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
