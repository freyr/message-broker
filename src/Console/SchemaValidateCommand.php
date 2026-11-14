<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Freyr\MessageBroker\Serializer\Avro\FileSchemaRegistry;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Avro\SchemaNotFound;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registry-free schema validation (CI gate). Replaces schema:register: with no
 * registry, "registering" is just committing the .avsc files, so the useful
 * pre-flight check is that the committed set is internally consistent —.
 *
 *   1. every committed schema version derives a UNIQUE id (no two distinct
 *      schemas collide onto one Confluent-frame id — the FileSchemaRegistry
 *      constructor enforces this), and
 *   2. every schema the PRODUCER will encode (the current FileSchemaStore map)
 *      is present in the all-versions committed set a consumer resolves against
 *      (the produce-time superset invariant).
 *
 * Backward/forward COMPATIBILITY between versions is a separate, reference-correct
 * CI gate (the offline Confluent maven checker), not this command.
 */
#[AsCommand(
    name: 'message-broker:schema:validate',
    description: 'Validate committed Avro schemas: unique derived ids + producer schemas resolvable',
)]
final class SchemaValidateCommand extends Command
{
    /** @param list<string> $schemaVersionPaths every committed schema version's .avsc path */
    public function __construct(
        private readonly FileSchemaStore $currentSchemas,
        private readonly array $schemaVersionPaths,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $registry = new FileSchemaRegistry($this->schemaVersionPaths);
        } catch (\RuntimeException $error) {
            $output->writeln("<error>Committed schema set is invalid: {$error->getMessage()}</error>");

            return Command::FAILURE;
        }

        $subjects = $this->currentSchemas->subjects();
        if ($subjects === []) {
            $output->writeln('<comment>No producer schemas mapped — nothing to validate.</comment>');

            return Command::SUCCESS;
        }

        $ok = true;
        foreach ($subjects as $subject) {
            try {
                $id = $registry->idFor($subject, $this->currentSchemas->schemaJsonFor($subject));
            } catch (SchemaNotFound) {
                $output->writeln("<error>{$subject}: current schema is not in the committed all-versions set</error>");
                $ok = false;
                continue;
            } catch (\RuntimeException $error) {
                $output->writeln("<error>{$subject}: {$error->getMessage()}</error>");
                $ok = false;
                continue;
            }

            $output->writeln("{$subject} → {$id}");
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
