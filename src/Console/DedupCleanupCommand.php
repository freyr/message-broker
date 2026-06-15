<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'message-broker:dedup:cleanup',
    description: 'Prune deduplication entries older than the given age',
)]
final class DedupCleanupCommand extends Command
{
    public function __construct(
        private readonly PdoDeduplicationStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than', null, InputOption::VALUE_REQUIRED, "Age threshold, e.g. '7d', '24h'", '7d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $olderThan = $input->getOption('older-than');
        if (!is_string($olderThan)) {
            $output->writeln('<error>--older-than must be a duration string</error>');

            return Command::INVALID;
        }

        $removed = $this->store->cleanup(EpochMillis::now() - Duration::toMilliseconds($olderThan));
        $output->writeln("<info>Removed {$removed} deduplication entries older than {$olderThan}.</info>");

        return Command::SUCCESS;
    }
}
