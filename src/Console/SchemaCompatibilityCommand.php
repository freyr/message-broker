<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Serializer\Avro\CompatibilityLevel;
use Freyr\MessageBroker\Serializer\Avro\SchemaRegistrar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Out-of-band compatibility governance (§8): set or show a subject's registry
 * compatibility level without re-registering its schema. Separate from the
 * register path; the runtime never touches compatibility.
 */
#[AsCommand(
    name: 'message-broker:schema:compatibility',
    description: "Set or show a subject's registry compatibility level",
)]
final class SchemaCompatibilityCommand extends Command
{
    public function __construct(
        private readonly SchemaRegistrar $registrar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'The subject (message_name) to govern')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Level to set (omit to show the current level)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $subject = $input->getOption('subject');
        if (!is_string($subject) || $subject === '') {
            $output->writeln('<error>--subject is required</error>');

            return Command::INVALID;
        }

        $levelOption = $input->getOption('level');

        // Read mode: no --level → print the current per-subject level (or the default).
        if (!is_string($levelOption)) {
            $current = $this->registrar->compatibilityOf($subject);
            $output->writeln("{$subject} → ".($current === null ? '(registry default)' : $current->value));

            return Command::SUCCESS;
        }

        $level = CompatibilityLevel::tryFrom($levelOption);
        if ($level === null) {
            $valid = implode(
                ', ',
                array_map(static fn (CompatibilityLevel $l): string => $l->value, CompatibilityLevel::cases())
            );
            $output->writeln("<error>Unknown level \"{$levelOption}\". Valid: {$valid}</error>");

            return Command::INVALID;
        }

        $this->registrar->setCompatibility($subject, $level);
        $output->writeln("{$subject} → {$level->value} (set)");

        return Command::SUCCESS;
    }
}
