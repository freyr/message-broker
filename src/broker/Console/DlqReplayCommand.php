<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\AlreadyReplayed;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterNotFound;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\DeadLetter\NotReplayable;
use Freyr\MessageBroker\DeadLetter\ReplayDestination;
use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

/**
 * Replays one dead letter, or each matching dead letter with --all. Each
 * replay is its own transaction ({@see ReplayService}). A webhook dead letter
 * (source "webhook:<lane>") goes to a new delivery row for the lane of its
 * source; --lane has no effect on it. The output names the webhook lane, and
 * the summary of --all counts the webhook replays apart.
 *
 * --all reads only open rows (replayed = false), with replayable = true unless
 * --not-replayable is given, and only rows that failed before the start of
 * the run (or before --until when it is earlier): a replayed message that
 * fails again during the run gets a new open row, which the run must not
 * replay again. It works in rounds over ids(), oldest first; a row that it
 * skips or that fails is held back and not asked again. A skip or a failure
 * does not stop the run. --limit caps the rows that the run handles, and
 * --rate the replays that start in one second.
 */
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
            ->addOption('lane', null, InputOption::VALUE_REQUIRED, 'Target outbox lane (no effect on a webhook dead letter)', 'default')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'With --all: the maximum number of rows that the run handles')
            ->addOption('rate', null, InputOption::VALUE_REQUIRED, 'With --all: the maximum number of replays that start in one second')
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
            return $this->replayOne($id, $lane, $input->getOption('dry-run') === true, $output);
        }

        if ($input->getOption('all') !== true) {
            $output->writeln('<error>Provide a dead letter id or --all.</error>');

            return Command::INVALID;
        }

        $startedAt = EpochMillis::now();
        try {
            $limit = self::positiveInteger($input, 'limit');
            $rate = self::positiveInteger($input, 'rate');
            $filter = DeadLetterFilterOptions::filter($input);
            // A bulk replay reads only open rows: a replayed row is refused.
            if ($filter->replayed === true) {
                $output->writeln('<error>--replayed cannot be used with --all: a bulk replay selects the rows that are not replayed.</error>');

                return Command::INVALID;
            }
            $filter = self::bulkFilter($filter, $startedAt);
        } catch (InvalidArgumentException $error) {
            $output->writeln("<error>{$error->getMessage()}</error>");

            return Command::INVALID;
        }

        $eligible = $this->store->count($filter);
        if ($limit !== null) {
            $eligible = min($eligible, $limit);
        }

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

        return $this->replayAll($filter, $lane, $limit, $rate, $output);
    }

    private function replayOne(string $id, string $lane, bool $dryRun, OutputInterface $output): int
    {
        try {
            if ($dryRun) {
                $destination = $this->replay->check($id, $lane);
                $output->writeln('<info>[dry-run] would replay '.self::into($id, $destination).'</info>');

                return Command::SUCCESS;
            }
            $destination = $this->replay->replay($id, $lane);
        } catch (DeadLetterNotFound|AlreadyReplayed|NotReplayable $refusal) {
            $output->writeln("<error>{$refusal->getMessage()}.</error>");

            return Command::FAILURE;
        }
        $output->writeln('<info>Replayed '.self::into($id, $destination).'</info>');

        return Command::SUCCESS;
    }

    /** "<id> into lane '<lane>'." for an outbox replay; a webhook replay names its webhook lane and says that --lane had no effect. */
    private static function into(string $id, ReplayDestination $destination): string
    {
        return $destination->webhook
            ? "{$id} into webhook lane '{$destination->lane}' (a webhook dead letter goes to the lane of its source; --lane has no effect)."
            : "{$id} into lane '{$destination->lane}'.";
    }

    /**
     * Rounds over ids(): each round asks for the rows left under the limit
     * (at most one batch) plus the held-back rows, and drops the held-back
     * ones. A replayed row leaves the filter (its mark is set), so the memory
     * of the run is the set of ids that it did not replay.
     */
    private function replayAll(DeadLetterFilter $filter, string $lane, ?int $limit, ?int $rate, OutputInterface $output): int
    {
        $replayed = 0;
        $webhookReplayed = 0;
        /** @var array<string, true> $webhookLanes in the order of the first replay */
        $webhookLanes = [];
        $notReplayable = 0;
        $alreadyReplayed = 0;
        $failed = 0;
        /** @var array<string, true> $heldBack */
        $heldBack = [];
        $handled = 0;
        $previousStart = null;

        while ($limit === null || $handled < $limit) {
            $wanted = $limit === null ? $this->batchSize : min($this->batchSize, $limit - $handled);
            $ids = array_filter(
                $this->store->ids($filter, $wanted + count($heldBack)),
                static fn (string $id): bool => !isset($heldBack[$id]),
            );
            if ($ids === []) {
                break;
            }
            foreach ($ids as $id) {
                if ($limit !== null && $handled >= $limit) {
                    break; // the while condition then ends the run
                }
                ++$handled;
                if ($rate !== null) {
                    $previousStart = self::waitForTheRate($previousStart, $rate);
                }
                try {
                    $destination = $this->replay->replay($id, $lane);
                    if ($destination->webhook) {
                        ++$webhookReplayed;
                        $webhookLanes[$destination->lane] = true;
                    } else {
                        ++$replayed;
                    }

                    continue;
                } catch (NotReplayable $skip) {
                    ++$notReplayable;
                    $output->writeln("Skipped: {$skip->getMessage()}.");
                } catch (AlreadyReplayed|DeadLetterNotFound) {
                    // Another run replayed it, or a purge removed it, after ids().
                    ++$alreadyReplayed;
                } catch (Throwable $error) {
                    ++$failed;
                    $output->writeln(sprintf('<error>Failed %s: %s: %s</error>', $id, $error::class, $error->getMessage()));
                }
                $heldBack[$id] = true;
            }
        }

        $output->writeln(sprintf(
            '<info>Replayed %d, skipped %d (not replayable), skipped %d (already replayed), failed %d into lane \'%s\'.</info>',
            $replayed,
            $notReplayable,
            $alreadyReplayed,
            $failed,
            $lane,
        ));
        if ($webhookReplayed > 0) {
            $output->writeln(sprintf(
                '<info>Replayed %d webhook dead letter(s) into the webhook lanes of their sources (--lane has no effect on them): %s.</info>',
                $webhookReplayed,
                implode(', ', array_keys($webhookLanes)),
            ));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The filter of --all: replayed = false; replayable = true unless the
     * operator asked for false; failed before the start of the run, or before
     * --until when it is earlier.
     *
     * @throws InvalidArgumentException when the bounds leave no time range (--since at or after the start)
     */
    private static function bulkFilter(DeadLetterFilter $filter, int $startedAt): DeadLetterFilter
    {
        return new DeadLetterFilter(
            messageName: $filter->messageName,
            source: $filter->source,
            errorClass: $filter->errorClass,
            messageId: $filter->messageId,
            failedFromMs: $filter->failedFromMs,
            failedUntilMs: min($filter->failedUntilMs ?? $startedAt, $startedAt),
            minAttempts: $filter->minAttempts,
            replayed: false,
            replayable: $filter->replayable ?? true,
        );
    }

    /**
     * Waits until 1 / rate seconds passed since the start of the previous
     * replay; returns the start of this replay (seconds of the clock).
     */
    private static function waitForTheRate(?float $previousStart, int $rate): float
    {
        $clock = Clock::get();
        if ($previousStart !== null) {
            $wait = $previousStart + 1 / $rate - (float) $clock->now()->format('U.u');
            if ($wait > 0) {
                $clock->sleep($wait);
            }
        }

        return (float) $clock->now()->format('U.u');
    }

    /** @throws InvalidArgumentException when the value is not a positive integer */
    private static function positiveInteger(InputInterface $input, string $option): ?int
    {
        $value = $input->getOption($option);
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^[1-9]\d*\z/', $value) !== 1) {
            $text = is_string($value) ? $value : get_debug_type($value);

            throw new InvalidArgumentException("Invalid --{$option} '{$text}': expected an integer of 1 or more");
        }

        return (int) $value;
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
