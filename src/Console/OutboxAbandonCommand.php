<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Abandons the open outbox rows of one message id: the relay never claims
 * them again, and retention drops them with their partition. For a message
 * that the transport can never accept; read the broker error first. The
 * reason goes to the log and to the event message.abandoned, not to the row.
 * No confirmation: the mark is reversible with SQL while the partition exists.
 */
#[AsCommand(
    name: 'message-broker:outbox:abandon',
    description: 'Abandon an outbox row so that the relay does not publish it',
)]
final class OutboxAbandonCommand extends Command
{
    public function __construct(
        private readonly OutboxStore $store,
        private readonly ?BrokerEvents $events = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Message id of the outbox row')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why the row is abandoned (to the log and the event)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        if (!is_string($id) || trim($id) === '') {
            $output->writeln('<error>The ID must not be empty.</error>');

            return Command::INVALID;
        }
        $reason = $input->getOption('reason');

        $count = $this->store->abandon($id);
        if ($count === 0) {
            $output->writeln('<error>No unpublished row with this ID: it is published, already abandoned, or does not exist.</error>');

            return Command::FAILURE;
        }

        $context = [
            'message_id' => $id,
            'count' => $count,
            'reason' => is_string($reason) ? $reason : null,
        ];
        $this->logger->warning('Outbox row abandoned by an operator; it will not be published', $context);
        $this->events?->record(BrokerEvents::ABANDONED, $context);
        $output->writeln("<info>Abandoned {$count} outbox row(s) with ID {$id}: the relay will not publish it.</info>");

        return Command::SUCCESS;
    }
}
