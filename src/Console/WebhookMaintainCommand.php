<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Webhook\DeliveryRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retention of the webhook_deliveries table, meant for a daily Kubernetes
 * CronJob, with the options and the exit codes of
 * message-broker:outbox:maintain: create the daily partitions ahead of time,
 * then drop the expired ones. An expired partition that still holds an
 * unfinished row is kept: it is reported and the command exits with FAILURE,
 * so the Job fails and alerts. There is no option to drop such a partition:
 * a delivery has no time limit, so the partition stays until each of its rows
 * reaches a final state or an operator abandons it with
 * message-broker:webhook:abandon.
 * A dry run changes nothing and always exits with SUCCESS.
 */
#[AsCommand(
    name: 'message-broker:webhook:maintain',
    description: 'Create upcoming webhook_deliveries partitions and drop expired ones',
)]
final class WebhookMaintainCommand extends Command
{
    public function __construct(
        private readonly DeliveryRetention $retention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Keep finished rows at least this many days', '7')
            ->addOption('premake-days', null, InputOption::VALUE_REQUIRED, 'Create daily partitions this many days ahead', '3')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionDays = self::intOption($input->getOption('retention-days'), '/\A[1-9]\d*\z/');
        $premakeDays = self::intOption($input->getOption('premake-days'), '/\A(0|[1-9]\d*)\z/');
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

        $report = $this->retention->purge($retentionDays, $dryRun);
        $output->writeln(sprintf('<info>%sRetention cutoff: %s UTC.</info>', $prefix, $report->cutoff->format('Y-m-d H:i')));
        foreach ($report->dropped as $name) {
            $output->writeln("<info>{$prefix}Dropped {$name}.</info>");
        }
        foreach ($report->kept as $name => $count) {
            $verb = $dryRun ? 'would be kept' : 'is kept';
            $output->writeln(
                "<error>{$prefix}{$name} {$verb}: it holds {$count} unfinished row(s) older than the retention period. "
                .'They wait for a final state; end old rows with message-broker:webhook:abandon --before=<date>.</error>',
            );
        }

        return $report->hasKept() && !$dryRun ? Command::FAILURE : Command::SUCCESS;
    }

    private static function intOption(mixed $value, string $pattern): ?int
    {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
