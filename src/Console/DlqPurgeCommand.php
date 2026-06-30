<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'message-broker:dlq:purge', description: 'Delete dead letters')]
final class DlqPurgeCommand extends Command
{
    public function __construct(
        private readonly PdoDeadLetterStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_REQUIRED,
            "Only purge failures older than, e.g. '30d' (default: everything)",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $olderThan = $input->getOption('older-than');
        $purged = $this->store->purge(
            olderThanMs: is_string($olderThan) ? EpochMillis::now() - Duration::toMilliseconds($olderThan) : null,
        );

        $output->writeln("<info>Purged {$purged} dead letters.</info>");

        return Command::SUCCESS;
    }
}
