<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Abandons the old unfinished webhook deliveries, by date: the only way to
 * end rows that no rule ends, for example the rows of a blocked lane or of a
 * subscription that the application removed. A delivery has no time limit.
 *
 * Each row with a created_at earlier than --before (of the --lane lanes, or
 * of all lanes) goes to the dead letters with the error class `abandoned` and
 * the source `webhook:<lane>`, so dlq:replay can send it again. The command
 * counts the rows of each lane (DeliveryStore::countUnfinished()), then calls
 * DeliveryStore::abandon() for each lane in batches until a call finds no
 * row, and prints the count of each lane. A row that a different session
 * locks is skipped, so a call can find no row while rows are left: the
 * command counts the lane again and prints the rows that are left, with a
 * note to run the command again. A dry run prints the counts and
 * changes nothing. A worker that holds the lease of an abandoned row can
 * still send its request; its result write changes no row.
 *
 * --before is a date (midnight UTC) or an ISO 8601 time; a time with no
 * offset is UTC. Exit codes: 0 success (also when no row matches, and always
 * on --dry-run); 2 invalid input.
 */
#[AsCommand(
    name: 'message-broker:webhook:abandon',
    description: 'Abandon the unfinished webhook deliveries created before a date (to the dead letters)',
)]
final class WebhookAbandonCommand extends Command
{
    private const string BEFORE_ERROR = '<error>--before must be a date (2026-01-31) or an ISO 8601 time (2026-01-31T12:00:00Z), in UTC when it has no offset.</error>';

    /** @param int $batchSize the most rows of one abandon() call */
    public function __construct(
        private readonly DeliveryStore $store,
        private readonly ?BrokerEvents $events = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $batchSize = 1_000,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Abandon the rows created before this date (2026-01-31) or ISO 8601 time; UTC when it has no offset')
            ->addOption('lane', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Abandon only the rows of this lane (a subscription id); repeat it for more lanes. Default: all lanes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the counts without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = self::before($input->getOption('before'));
        if ($before === null) {
            $output->writeln(self::BEFORE_ERROR);

            return Command::INVALID;
        }
        /** @var list<string> $laneOption */
        $laneOption = $input->getOption('lane');
        if (in_array('', $laneOption, true)) {
            $output->writeln('<error>--lane must not be empty.</error>');

            return Command::INVALID;
        }
        // A repeated lane is harmless: the counts are keyed by lane.
        $lanes = $laneOption === [] ? null : $laneOption;
        $dryRun = $input->getOption('dry-run') === true;
        $prefix = $dryRun ? '[dry-run] ' : '';
        $date = EpochMillis::toDateTime($before)->format('Y-m-d H:i:s.v');

        $counts = $this->store->countUnfinished($before, $lanes);
        if ($counts === []) {
            $output->writeln("<info>{$prefix}No unfinished delivery was created before {$date} UTC.</info>");

            return Command::SUCCESS;
        }

        $verb = $dryRun ? 'would be abandoned' : 'abandoned';
        $output->writeln("<info>{$prefix}Unfinished deliveries created before {$date} UTC:</info>");
        $total = 0;
        foreach ($counts as $lane => $count) {
            $lane = (string) $lane; // a numeric lane name is an int key
            if (!$dryRun) {
                $count = $this->abandonLane($before, $lane);
            }
            $total += $count;
            $output->writeln("<info>{$prefix}{$lane}: {$count} row(s) {$verb}.</info>");
            if (!$dryRun) {
                $left = array_sum($this->store->countUnfinished($before, [$lane]));
                if ($left > 0) {
                    $output->writeln(
                        "<comment>{$lane}: {$left} row(s) left (locked by a different session); run the command again.</comment>",
                    );
                }
            }
        }
        $output->writeln("<info>{$prefix}Total: {$total} row(s) {$verb}.</info>");

        return Command::SUCCESS;
    }

    /** @return int the rows of the lane that the calls abandoned */
    private function abandonLane(int $before, string $lane): int
    {
        $count = 0;
        do {
            $abandoned = $this->store->abandon($before, [$lane], $this->batchSize);
            $count += $abandoned;
        } while ($abandoned > 0);

        if ($count > 0) {
            $this->logger->warning('Webhook deliveries abandoned by an operator; they go to the dead letters', [
                'lane' => $lane,
                'count' => $count,
                'before' => $before,
            ]);
            $this->events?->record(BrokerEvents::WEBHOOK_ABANDONED, [
                'lane' => $lane,
                'count' => $count,
            ]);
        }

        return $count;
    }

    /** @return int|null epoch milliseconds; null when the value is not a date or an ISO 8601 time */
    private static function before(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        foreach (['!Y-m-d', '!Y-m-d\TH:i:sP', '!Y-m-d\TH:i:s'] as $format) {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value, $utc);
            if ($dateTime !== false && DateTimeImmutable::getLastErrors() === false) {
                return EpochMillis::fromDateTime($dateTime);
            }
        }

        return null;
    }
}
