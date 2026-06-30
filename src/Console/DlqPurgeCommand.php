<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

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
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Only this message name')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only this source queue/topic')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, "Only failures older than, e.g. '30d'")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many would be deleted; change nothing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required to delete non-interactively');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('name');
        $source = $input->getOption('source');
        $olderThan = $input->getOption('older-than');

        $messageName = is_string($name) ? $name : null;
        $sourceFilter = is_string($source) ? $source : null;
        $olderThanMs = is_string($olderThan) ? EpochMillis::now() - Duration::toMilliseconds($olderThan) : null;

        // count ignores older-than; the actual delete applies it. Good enough for a preview headline.
        $matching = $this->store->count(messageName: $messageName, source: $sourceFilter);

        if ($input->getOption('dry-run') === true) {
            $output->writeln("<info>[dry-run] would delete up to {$matching} dead letters.</info>");

            return Command::SUCCESS;
        }

        if ($input->getOption('force') !== true) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>Refusing to purge without --force (no interactive terminal).</error>');

                return Command::FAILURE;
            }
            $helper = $this->getHelper('question');
            \assert($helper instanceof QuestionHelper);
            if (!$helper->ask(
                $input,
                $output,
                new ConfirmationQuestion("Delete up to {$matching} dead letters? [y/N] ", false)
            )) {
                return Command::FAILURE;
            }
        }

        $purged = $this->store->purge(messageName: $messageName, source: $sourceFilter, olderThanMs: $olderThanMs);
        $output->writeln("<info>Purged {$purged} dead letters.</info>");

        return Command::SUCCESS;
    }
}
