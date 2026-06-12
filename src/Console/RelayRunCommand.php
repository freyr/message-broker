<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running relay process, one per lane. There is no common relay
 * interface (transports are deliberately distinct), so the application
 * registers runners as callables:
 *
 *     new RelayRunCommand([
 *         'orders' => fn () => $ordersAmqpRelay->run(),
 *         'analytics' => fn () => $analyticsKafkaRelay->run(),  // slice 3
 *     ]);
 */
#[AsCommand(name: 'message-broker:relay:run', description: 'Run the relay for one outbox lane (blocks)')]
final class RelayRunCommand extends Command
{
    /** @param array<string, callable(): void> $relays keyed by lane */
    public function __construct(
        private readonly array $relays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('lane', InputArgument::REQUIRED, 'Outbox lane to drain');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lane = $input->getArgument('lane');
        if (!is_string($lane) || !isset($this->relays[$lane])) {
            $known = implode(', ', array_keys($this->relays));
            $output->writeln("<error>Unknown lane. Registered lanes: {$known}</error>");

            return Command::INVALID;
        }

        $output->writeln("<info>Relaying lane '{$lane}' — SIGTERM/SIGINT to stop.</info>");
        ($this->relays[$lane])();

        return Command::SUCCESS;
    }
}
