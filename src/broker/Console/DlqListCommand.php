<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;
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
        private readonly DeadLetterStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        DeadLetterFilterOptions::configure($this);
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows', '100')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip this many rows', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $filter = DeadLetterFilterOptions::filter($input);
        } catch (InvalidArgumentException $error) {
            $output->writeln("<error>{$error->getMessage()}</error>");

            return Command::INVALID;
        }
        $limit = $input->getOption('limit');
        $offset = $input->getOption('offset');

        $deadLetters = $this->store->list(
            $filter,
            limit: is_numeric($limit) ? (int) $limit : 100,
            offset: is_numeric($offset) ? (int) $offset : 0,
        );

        if ($deadLetters === []) {
            $output->writeln('<info>Dead letter queue is empty.</info>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['id', 'message_name', 'source', 'attempts', 'failed_at', 'replayed', 'replayable', 'error']);
        foreach ($deadLetters as $deadLetter) {
            $table->addRow([
                $deadLetter->id,
                $deadLetter->messageName,
                $deadLetter->source,
                $deadLetter->attempts,
                EpochMillis::toDateTime($deadLetter->failedAt)->format('Y-m-d H:i:s.v'),
                $deadLetter->replayedAt !== null ? 'yes' : 'no',
                $deadLetter->replayable ? 'yes' : 'no',
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
