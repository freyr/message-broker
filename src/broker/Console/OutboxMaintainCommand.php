<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Outbox\OutboxRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Outbox retention, meant for a daily schedule: create the daily partitions
 * ahead of time, then drop the expired ones (DETACH ... CONCURRENTLY, then
 * DROP). An expired partition that still holds unpublished rows is kept by
 * default: it is reported and the command exits with FAILURE, so a scheduler
 * alerts. --drop-unpublished makes the retention period a hard age limit: such
 * a partition is dropped with its unpublished rows, and the command warns.
 * An abandoned row does not keep its partition: the command prints the count
 * of abandoned rows of each dropped partition, and the exit code does not change.
 * A dry run changes nothing and always exits with SUCCESS.
 */
#[AsCommand(
    name: 'message-broker:outbox:maintain',
    description: 'Create upcoming outbox partitions and drop expired ones',
)]
final class OutboxMaintainCommand extends Command
{
    public function __construct(
        private readonly OutboxRetention $retention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Keep rows at least this many days; an expired partition with unpublished rows is kept unless --drop-unpublished', '7')
            ->addOption('premake-days', null, InputOption::VALUE_REQUIRED, 'Create daily partitions this many days ahead', '3')
            ->addOption('drop-unpublished', null, InputOption::VALUE_NONE, 'Also drop expired partitions that hold unpublished rows (data loss); by default they are kept')
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
            $created = $this->retention->ensureCapacity($premakeDays);
            $output->writeln(sprintf('<info>Daily partitions present: %s.</info>', implode(', ', $created)));
        }

        $report = $this->retention->purge($retentionDays, $dryRun, dropUnpublished: $input->getOption('drop-unpublished') === true);
        $output->writeln(sprintf('<info>%sRetention cutoff: %s UTC.</info>', $prefix, $report->cutoff->format('Y-m-d H:i')));
        foreach ($report->dropped as $name) {
            $output->writeln("<info>{$prefix}Dropped {$name}.</info>");
        }
        foreach ($report->unpublished as $name => $count) {
            $output->writeln("<comment>{$prefix}{$name} held {$count} unpublished row(s) older than the retention period; they are dropped.</comment>");
        }
        foreach ($report->abandoned as $name => $count) {
            $output->writeln("<info>{$prefix}{$name} held {$count} abandoned row(s); they are dropped.</info>");
        }
        foreach ($report->kept as $name => $count) {
            $verb = $dryRun ? 'would be kept' : 'is kept';
            $output->writeln("<error>{$prefix}{$name} {$verb}: it holds {$count} unpublished row(s) older than the retention period. Publish them, or use --drop-unpublished to drop them.</error>");
        }

        return $report->hasKept() && !$dryRun ? Command::FAILURE : Command::SUCCESS;
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
