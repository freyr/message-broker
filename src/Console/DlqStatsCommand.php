<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One table row for each group (message name, source, error class) of the
 * matching dead letters. It prints no body and no error message, so its
 * output is safe to share in a ticket.
 */
#[AsCommand(name: 'message-broker:dlq:stats', description: 'Count dead letters by message name, source and error class')]
final class DlqStatsCommand extends Command
{
    public function __construct(
        private readonly DeadLetterStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        DeadLetterFilterOptions::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $filter = DeadLetterFilterOptions::filter($input);
        } catch (InvalidArgumentException $error) {
            $output->writeln("<error>{$error->getMessage()}</error>");

            return Command::INVALID;
        }

        $stats = $this->store->stats($filter);
        if ($stats === []) {
            $output->writeln('<info>No dead letters match.</info>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['message_name', 'source', 'error_class', 'count', 'oldest_failed_at', 'newest_failed_at']);
        foreach ($stats as $stat) {
            $table->addRow([
                $stat->messageName,
                $stat->source,
                $stat->errorClass,
                $stat->count,
                EpochMillis::toDateTime($stat->oldestFailedAt)->format('Y-m-d H:i:s.v'),
                EpochMillis::toDateTime($stat->newestFailedAt)->format('Y-m-d H:i:s.v'),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
