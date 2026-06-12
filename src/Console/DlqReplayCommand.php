<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'message-broker:dlq:replay',
    description: 'Re-enqueue dead letters into the outbox (redelivery rides the relay path)',
)]
final class DlqReplayCommand extends Command
{
    public function __construct(
        private readonly ReplayService $replay,
        private readonly PdoDeadLetterStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'Dead letter id (omit with --all)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Replay every non-replayed dead letter')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'With --all: only this message name')
            ->addOption('lane', null, InputOption::VALUE_REQUIRED, 'Target outbox lane', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lane = $input->getOption('lane');
        $lane = is_string($lane) ? $lane : 'default';

        $id = $input->getArgument('id');
        if (is_string($id)) {
            $this->replay->replay($id, $lane);
            $output->writeln("<info>Replayed {$id} into lane '{$lane}'.</info>");

            return Command::SUCCESS;
        }

        if ($input->getOption('all') !== true) {
            $output->writeln('<error>Provide a dead letter id or --all.</error>');

            return Command::INVALID;
        }

        $name = $input->getOption('name');
        $replayed = 0;
        foreach ($this->store->list(messageName: is_string($name) ? $name : null, limit: PHP_INT_MAX) as $deadLetter) {
            if ($deadLetter->replayedAt !== null) {
                continue;
            }
            $this->replay->replay($deadLetter->id, $lane);
            ++$replayed;
        }

        $output->writeln("<info>Replayed {$replayed} dead letters into lane '{$lane}'.</info>");

        return Command::SUCCESS;
    }
}
