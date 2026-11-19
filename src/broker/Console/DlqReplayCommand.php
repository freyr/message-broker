<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(
    name: 'message-broker:dlq:replay',
    description: 'Re-enqueue dead letters into the outbox (redelivery rides the relay path)',
)]
final class DlqReplayCommand extends Command
{
    public function __construct(
        private readonly ReplayService $replay,
        private readonly DeadLetterStore $store,
        private readonly int $batchSize = 500,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'Dead letter id (omit with --all)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Replay every matching dead letter that is not replayed')
            ->addOption('lane', null, InputOption::VALUE_REQUIRED, 'Target outbox lane', 'default')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be replayed; change nothing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required to run --all non-interactively');
        DeadLetterFilterOptions::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lane = $input->getOption('lane');
        $lane = is_string($lane) ? $lane : 'default';

        $id = $input->getArgument('id');
        if (is_string($id)) {
            if ($input->getOption('dry-run') === true) {
                $output->writeln("<info>[dry-run] would replay {$id} into lane '{$lane}'.</info>");

                return Command::SUCCESS;
            }
            $this->replay->replay($id, $lane);
            $output->writeln("<info>Replayed {$id} into lane '{$lane}'.</info>");

            return Command::SUCCESS;
        }

        if ($input->getOption('all') !== true) {
            $output->writeln('<error>Provide a dead letter id or --all.</error>');

            return Command::INVALID;
        }

        try {
            $filter = DeadLetterFilterOptions::filter($input);
        } catch (InvalidArgumentException $error) {
            $output->writeln("<error>{$error->getMessage()}</error>");

            return Command::INVALID;
        }
        // The loop below reads a new page at offset 0 until it is empty, and the
        // replay of a replayed row keeps it replayed: a replayed row in the
        // filter would make the loop endless.
        if ($filter->replayed === true) {
            $output->writeln('<error>--replayed cannot be used with --all: a bulk replay selects the rows that are not replayed.</error>');

            return Command::INVALID;
        }
        $filter = $filter->withReplayed(false);
        $eligible = $this->store->count($filter);

        if ($input->getOption('dry-run') === true) {
            $output->writeln("<info>[dry-run] would replay {$eligible} dead letters into lane '{$lane}'.</info>");
            $sample = $this->store->list($filter, limit: 5);
            foreach ($sample as $deadLetter) {
                $output->writeln("  {$deadLetter->id}  {$deadLetter->messageName}");
            }

            return Command::SUCCESS;
        }

        if (!$this->confirmed($input, $output, "Replay {$eligible} dead letters into lane '{$lane}'?")) {
            return Command::FAILURE;
        }

        // Bounded batches keep memory flat on large DLQs. Each replay marks the
        // row, dropping it out of the replayed-IS-NULL filter, so a fresh page
        // at offset 0 always holds the next unprocessed rows.
        $replayed = 0;
        while (true) {
            $batch = $this->store->list($filter, limit: $this->batchSize);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $deadLetter) {
                $this->replay->replay($deadLetter->id, $lane);
                ++$replayed;
            }
        }
        $output->writeln("<info>Replayed {$replayed} dead letters into lane '{$lane}'.</info>");

        return Command::SUCCESS;
    }

    private function confirmed(InputInterface $input, OutputInterface $output, string $prompt): bool
    {
        if ($input->getOption('force') === true) {
            return true;
        }
        if (!$input->isInteractive()) {
            $output->writeln('<error>Refusing a batch operation without --force (no interactive terminal).</error>');

            return false;
        }
        $helper = $this->getHelper('question');
        \assert($helper instanceof QuestionHelper);

        return (bool) $helper->ask($input, $output, new ConfirmationQuestion("{$prompt} [y/N] ", false));
    }
}
