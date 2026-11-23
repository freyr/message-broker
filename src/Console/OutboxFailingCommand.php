<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the open outbox rows of a lane with failed publish attempts (also the
 * rows that are not due yet), most attempts first, then oldest first. A row
 * that the transport refuses again and again shows here; the operator reads
 * the broker error and can abandon it with message-broker:outbox:abandon.
 * It shows the body size, never the body.
 */
#[AsCommand(
    name: 'message-broker:outbox:failing',
    description: 'List open outbox rows with failed publish attempts',
)]
final class OutboxFailingCommand extends Command
{
    public function __construct(
        private readonly OutboxStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lane', null, InputOption::VALUE_REQUIRED, 'Outbox lane', 'default')
            ->addOption('min-attempts', null, InputOption::VALUE_REQUIRED, 'Only rows with at least this many failed attempts', '3')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $minAttempts = self::intOption($input->getOption('min-attempts'));
        $limit = self::intOption($input->getOption('limit'));
        if ($minAttempts === null || $limit === null) {
            $output->writeln('<error>--min-attempts and --limit must be integers of 1 or more.</error>');

            return Command::INVALID;
        }
        $lane = $input->getOption('lane');
        $lane = is_string($lane) ? $lane : 'default';

        $rows = $this->store->failing($lane, $minAttempts, $limit);
        if ($rows === []) {
            $output->writeln("<info>No open outbox row in lane {$lane} with {$minAttempts} or more attempts.</info>");

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['id', 'lane', 'message_name', 'attempts', 'created_at', 'next_attempt_at', 'body_bytes']);
        foreach ($rows as $row) {
            $table->addRow([
                $row->id,
                $row->lane,
                $row->messageName,
                $row->attempts,
                EpochMillis::toDateTime($row->createdAt)->format('Y-m-d H:i:s.v'),
                EpochMillis::toDateTime($row->availableAt)->format('Y-m-d H:i:s.v'),
                $row->bodyBytes,
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private static function intOption(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9]\d*\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
