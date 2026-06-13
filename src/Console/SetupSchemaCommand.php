<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Storage\Platform;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'message-broker:setup:schema',
    description: 'Create the outbox_messages, message_deduplication and dead_letters tables',
)]
final class SetupSchemaCommand extends Command
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Platform $platform,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Wire format: json or avro', 'json')
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the DDL instead of executing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) ? Format::tryFrom($formatOption) : null;
        if ($format === null) {
            $output->writeln('<error>--format must be "json" or "avro".</error>');

            return Command::INVALID;
        }

        foreach ($this->platform->schemaSql($format) as $ddl) {
            if ($input->getOption('dump-sql') === true) {
                $output->writeln($ddl.';');
                continue;
            }

            $this->pdo->exec($ddl);
        }

        if ($input->getOption('dump-sql') !== true) {
            $output->writeln("<info>Schema is up to date ({$format->value}).</info>");
        }

        return Command::SUCCESS;
    }
}
