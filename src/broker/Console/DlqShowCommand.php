<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'message-broker:dlq:show', description: 'Show one dead letter in full detail')]
final class DlqShowCommand extends Command
{
    public function __construct(
        private readonly DeadLetterStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Dead letter id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        $deadLetter = is_string($id) ? $this->store->find($id) : null;
        if ($deadLetter === null) {
            $output->writeln('<error>Dead letter not found.</error>');

            return Command::FAILURE;
        }

        $output->writeln("id:            {$deadLetter->id}");
        $output->writeln("source:        {$deadLetter->source}");
        $output->writeln("message_id:    {$deadLetter->messageId}");
        $output->writeln("message_name:  {$deadLetter->messageName}");
        $output->writeln("attempts:      {$deadLetter->attempts}");
        $output->writeln('failed_at:     '.EpochMillis::toDateTime($deadLetter->failedAt)->format('Y-m-d H:i:s.v'));
        $output->writeln('replayed_at:   '.($deadLetter->replayedAt !== null
            ? EpochMillis::toDateTime($deadLetter->replayedAt)->format('Y-m-d H:i:s.v')
            : '-'));
        $output->writeln("error_class:   {$deadLetter->errorClass}");
        $output->writeln("error_message: {$deadLetter->errorMessage}");
        $output->writeln('headers:       '.json_encode($deadLetter->headers));
        $output->writeln('body:');
        $output->writeln($deadLetter->body);
        $output->writeln('trace:');
        $output->writeln($deadLetter->errorTrace);

        return Command::SUCCESS;
    }
}
