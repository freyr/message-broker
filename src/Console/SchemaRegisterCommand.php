<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Serializer\Avro\CompatibilityLevel;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Avro\SchemaRegistrar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Out-of-band schema registration (A1) shipped as a tested code path. Driven
 * by the SAME FileSchemaStore map the producer uses, so CI registers exactly
 * the subjects produce will look up. The runtime never registers.
 */
#[AsCommand(
    name: 'message-broker:schema:register',
    description: 'Register mapped Avro schemas with the registry (out-of-band CI step)',
)]
final class SchemaRegisterCommand extends Command
{
    public function __construct(
        private readonly FileSchemaStore $schemas,
        private readonly SchemaRegistrar $registrar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Register only this subject (message_name)')
            ->addOption(
                'compatibility',
                null,
                InputOption::VALUE_REQUIRED,
                'Pin the subject compatibility level before registering (e.g. FULL, BACKWARD)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List subjects that would be registered without writing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('subject');
        $subjects = is_string($only) ? [$only] : $this->schemas->subjects();

        if ($subjects === []) {
            $output->writeln('<comment>No schemas mapped — nothing to register.</comment>');

            return Command::SUCCESS;
        }

        $compatibilityOption = $input->getOption('compatibility');
        $compatibility = null;
        if (is_string($compatibilityOption)) {
            $compatibility = CompatibilityLevel::tryFrom($compatibilityOption);
            if ($compatibility === null) {
                $valid = implode(
                    ', ',
                    array_map(static fn (CompatibilityLevel $l): string => $l->value, CompatibilityLevel::cases())
                );
                $output->writeln(
                    "<error>Unknown compatibility level \"{$compatibilityOption}\". Valid: {$valid}</error>"
                );

                return Command::INVALID;
            }
        }

        $dryRun = $input->getOption('dry-run') === true;

        foreach ($subjects as $subject) {
            $schemaJson = $this->schemas->schemaJsonFor($subject);

            if ($dryRun) {
                $suffix = $compatibility !== null ? " (compatibility={$compatibility->value})" : '';
                $output->writeln("would register: {$subject}{$suffix}");
                continue;
            }

            $id = $this->registrar->register($subject, $schemaJson, $compatibility);
            $output->writeln("{$subject} → {$id}");
        }

        return Command::SUCCESS;
    }
}
