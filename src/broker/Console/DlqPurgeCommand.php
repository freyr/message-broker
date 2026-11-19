<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use InvalidArgumentException;
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
        private readonly DeadLetterStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        DeadLetterFilterOptions::configure($this);
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, "Only failures older than, e.g. '30d'")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many would be deleted; change nothing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required to delete non-interactively');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $filter = self::filter($input);
        } catch (InvalidArgumentException $error) {
            $output->writeln("<error>{$error->getMessage()}</error>");

            return Command::INVALID;
        }

        // The count and the delete are two statements: a row that arrives between them can change the count.
        $matching = $this->store->count($filter);

        if ($input->getOption('dry-run') === true) {
            $output->writeln("<info>[dry-run] would delete {$matching} dead letters.</info>");

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
                new ConfirmationQuestion("Delete {$matching} dead letters? [y/N] ", false)
            )) {
                return Command::FAILURE;
            }
        }

        $purged = $this->store->purge($filter);
        $output->writeln("<info>Purged {$purged} dead letters.</info>");

        return Command::SUCCESS;
    }

    /**
     * The filter of the shared options; --older-than sets the upper bound of failed_at.
     *
     * @throws InvalidArgumentException when an option is invalid, or --older-than comes with --until
     */
    private static function filter(InputInterface $input): DeadLetterFilter
    {
        $filter = DeadLetterFilterOptions::filter($input);
        $olderThan = $input->getOption('older-than');
        if (!is_string($olderThan)) {
            return $filter;
        }
        if ($filter->failedUntilMs !== null) {
            throw new InvalidArgumentException('Use --older-than or --until, not both.');
        }

        return new DeadLetterFilter(
            messageName: $filter->messageName,
            source: $filter->source,
            errorClass: $filter->errorClass,
            messageId: $filter->messageId,
            failedFromMs: $filter->failedFromMs,
            failedUntilMs: DeadLetterFilterOptions::ago($olderThan, 'older-than'),
            minAttempts: $filter->minAttempts,
            replayed: $filter->replayed,
            replayable: $filter->replayable,
        );
    }
}
