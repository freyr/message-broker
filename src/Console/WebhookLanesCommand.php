<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\LaneState;
use Freyr\MessageBroker\Webhook\LaneStates;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows the circuit breaker state of each webhook lane, through the
 * LaneStates port: one table row for each lane that had a result, in the
 * order of the lane ID, with its state (blocked, open, or in circulation at
 * the time of the command), the counters of its window, open_until,
 * blocked_at, opened_count and consecutive_openings. The times are UTC.
 *
 * --close=<lane> puts the lane back into circulation: it ends an opening or a
 * block (LaneStates::close()), and it is the only way to end a block. The
 * next claim of a worker includes the lane again.
 *
 * Exit codes: 0 success; 1 the lane of --close has no state; 2 invalid input.
 */
#[AsCommand(
    name: 'message-broker:webhook:lanes',
    description: 'Show the circuit breaker state of each webhook lane, or put one lane back into circulation',
)]
final class WebhookLanesCommand extends Command
{
    public function __construct(
        private readonly LaneStates $lanes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('close', null, InputOption::VALUE_REQUIRED, 'Put this lane back into circulation: end its opening or its block');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $close = $input->getOption('close');
        if (is_string($close)) {
            return $this->close($close, $output);
        }

        $states = $this->lanes->all();
        if ($states === []) {
            $output->writeln('<info>No lane has a circuit breaker state.</info>');

            return Command::SUCCESS;
        }

        $now = EpochMillis::now();
        $table = new Table($output);
        $table->setHeaders(['lane', 'state', 'window_started_at', 'requests', 'failures', 'open_until', 'blocked_at', 'opened_count', 'consecutive_openings']);
        foreach ($states as $state) {
            $table->addRow([
                $state->lane,
                self::state($state, $now),
                self::time($state->windowStartedAt),
                $state->requests,
                $state->failures,
                self::time($state->openUntil),
                self::time($state->blockedAt),
                $state->openedCount,
                $state->consecutiveOpenings,
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private function close(string $lane, OutputInterface $output): int
    {
        if ($lane === '') {
            $output->writeln('<error>--close must name a lane.</error>');

            return Command::INVALID;
        }
        if (!$this->lanes->close($lane)) {
            $output->writeln("<error>The lane \"{$lane}\" has no circuit breaker state.</error>");

            return Command::FAILURE;
        }
        $output->writeln("<info>The lane \"{$lane}\" is in circulation again.</info>");

        return Command::SUCCESS;
    }

    private static function state(LaneState $state, int $now): string
    {
        if ($state->blockedAt !== null) {
            return 'blocked';
        }

        if ($state->openUntil === null) {
            return 'in circulation';
        }

        return $state->openUntil > $now ? 'open' : 'in circulation';
    }

    private static function time(?int $epochMilliseconds): string
    {
        return $epochMilliseconds === null ? '-' : EpochMillis::toDateTime($epochMilliseconds)->format('Y-m-d H:i:s.v');
    }
}
