<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'message-broker:dlq:list', description: 'List dead letters')]
final class DlqListCommand extends Command
{
    public function __construct(
        private readonly PdoDeadLetterStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Filter by message name')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Filter by source queue/topic')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, "Only failures younger than, e.g. '24h'")
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('name');
        $source = $input->getOption('source');
        $since = $input->getOption('since');
        $limit = $input->getOption('limit');

        $deadLetters = $this->store->list(
            messageName: is_string($name) ? $name : null,
            source: is_string($source) ? $source : null,
            sinceMs: is_string($since) ? EpochMillis::now() - Duration::toMilliseconds($since) : null,
            limit: is_numeric($limit) ? (int) $limit : 100,
        );

        if ($deadLetters === []) {
            $output->writeln('<info>Dead letter queue is empty.</info>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['id', 'message_name', 'source', 'attempts', 'failed_at', 'replayed', 'error']);
        foreach ($deadLetters as $deadLetter) {
            $table->addRow([
                $deadLetter->id,
                $deadLetter->messageName,
                $deadLetter->source,
                $deadLetter->attempts,
                EpochMillis::toDateTime($deadLetter->failedAt)->format('Y-m-d H:i:s.v'),
                $deadLetter->replayedAt !== null ? 'yes' : 'no',
                self::truncate($deadLetter->errorMessage),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private static function truncate(string $message): string
    {
        return mb_strlen($message) > 60 ? mb_substr($message, 0, 57).'...' : $message;
    }
}
