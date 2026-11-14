<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Outbox\OutboxPartitionManager;
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
    description: 'Create the outbox_messages (with its first daily partitions), message_deduplication and dead_letters tables',
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
            ->addOption('premake-days', null, InputOption::VALUE_REQUIRED, 'Create daily outbox partitions this many days ahead', '3')
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

        $premakeOption = $input->getOption('premake-days');
        if (!is_string($premakeOption) || preg_match('/^\d+$/', $premakeOption) !== 1) {
            $output->writeln('<error>--premake-days must be an integer of 0 or more.</error>');

            return Command::INVALID;
        }

        $partitions = new OutboxPartitionManager($this->pdo);
        $premakeDays = (int) $premakeOption;

        if ($input->getOption('dump-sql') === true) {
            // For a new database: the partition DDL is not idempotent (ATTACH).
            foreach ([...$this->platform->schemaSql($format), ...$partitions->partitionDdl($premakeDays)] as $statement) {
                $output->writeln($statement.';');
            }

            return Command::SUCCESS;
        }

        foreach ($this->platform->schemaSql($format) as $statement) {
            $this->pdo->exec($statement);
        }
        $partitions->ensurePartitions($premakeDays); // skips partitions that are already attached
        $output->writeln("<info>Schema is up to date ({$format->value}).</info>");

        return Command::SUCCESS;
    }
}
