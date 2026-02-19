<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Configuration\Configuration as MigrationsConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'message-broker:setup-deduplication', description: 'Create the deduplication table from configuration')]
final class SetupDeduplicationCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
        private readonly ?MigrationsConfiguration $migrationsConfiguration = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Execute the table creation directly');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the SQL without executing (default behaviour)');
        $this->addOption('migration', null, InputOption::VALUE_NONE, 'Generate a Doctrine migration file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');
        $migration = $input->getOption('migration');

        $activeModes = ($force ? 1 : 0) + ($dryRun ? 1 : 0) + ($migration ? 1 : 0);
        if ($activeModes > 1) {
            $io->error('The --force, --migration, and --dry-run options are mutually exclusive.');

            return Command::FAILURE;
        }

        if ($migration) {
            return $this->executeMigrationMode($io);
        }

        if ($force) {
            return $this->executeForceMode($io);
        }

        return $this->executeDryRunMode($io);
    }

    private function executeDryRunMode(SymfonyStyle $io): int
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist([$this->tableName])) {
            $io->success(sprintf("Table '%s' already exists. No action needed.", $this->tableName));

            return Command::SUCCESS;
        }

        $table = $this->createTableSchema();
        $platform = $this->connection->getDatabasePlatform();
        $statements = $platform->getCreateTableSQL($table);

        $io->section('SQL statements to create the deduplication table:');
        foreach ($statements as $statement) {
            $io->text($statement . ';');
        }

        return Command::SUCCESS;
    }

    private function executeForceMode(SymfonyStyle $io): int
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist([$this->tableName])) {
            $io->success(sprintf("Table '%s' already exists, skipping.", $this->tableName));

            return Command::SUCCESS;
        }

        $table = $this->createTableSchema();

        try {
            $schemaManager->createTable($table);
        } catch (\Doctrine\DBAL\Exception\TableExistsException) {
            $io->success(sprintf("Table '%s' already exists, skipping.", $this->tableName));

            return Command::SUCCESS;
        }

        $io->success(sprintf("Table '%s' created successfully.", $this->tableName));

        return Command::SUCCESS;
    }

    private function executeMigrationMode(SymfonyStyle $io): int
    {
        if (!class_exists(AbstractMigration::class)) {
            $io->error('The --migration option requires doctrine/doctrine-migrations-bundle. Install it with: composer require doctrine/doctrine-migrations-bundle');

            return Command::FAILURE;
        }

        if ($this->migrationsConfiguration === null) {
            $io->error('Could not determine migrations configuration. Ensure doctrine/doctrine-migrations-bundle is properly configured.');

            return Command::FAILURE;
        }

        $migrationDirectories = $this->migrationsConfiguration->getMigrationDirectories();
        if ($migrationDirectories === []) {
            $io->error('No migration directories configured. Add migrations_paths to your doctrine_migrations configuration.');

            return Command::FAILURE;
        }

        $namespace = array_key_first($migrationDirectories);
        $migrationsDir = $migrationDirectories[$namespace];
        $className = 'Version' . date('YmdHis');
        $filePath = rtrim($migrationsDir, '/') . '/' . $className . '.php';

        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0o755, true);
        }

        $content = $this->generateMigrationContent($namespace, $className, $this->tableName);
        file_put_contents($filePath, $content);

        $io->success(sprintf('Migration file generated: %s', $filePath));

        return Command::SUCCESS;
    }

    private function createTableSchema(): Table
    {
        $table = new Table($this->tableName);
        $table->addColumn('message_id', Types::BINARY, [
            'length' => 16,
            'fixed' => true,
            'notnull' => true,
            'comment' => '(DC2Type:id_binary)',
        ]);
        $table->addColumn('message_name', Types::STRING, [
            'length' => 255,
            'notnull' => true,
        ]);
        $table->addColumn('processed_at', Types::DATETIME_MUTABLE, [
            'notnull' => true,
        ]);
        $table->setPrimaryKey(['message_id']);
        $table->addIndex(['message_name'], 'idx_dedup_message_name');
        $table->addIndex(['processed_at'], 'idx_dedup_processed_at');

        return $table;
    }

    private function generateMigrationContent(string $namespace, string $className, string $tableName): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Doctrine\DBAL\Schema\Schema;
            use Doctrine\DBAL\Types\Types;
            use Doctrine\Migrations\AbstractMigration;

            final class {$className} extends AbstractMigration
            {
                public function getDescription(): string
                {
                    return 'Create {$tableName} table for deduplication tracking';
                }

                public function up(Schema \$schema): void
                {
                    \$table = \$schema->createTable('{$tableName}');
                    \$table->addColumn('message_id', Types::BINARY, [
                        'length' => 16,
                        'fixed' => true,
                        'notnull' => true,
                        'comment' => '(DC2Type:id_binary)',
                    ]);
                    \$table->addColumn('message_name', Types::STRING, [
                        'length' => 255,
                        'notnull' => true,
                    ]);
                    \$table->addColumn('processed_at', Types::DATETIME_MUTABLE, [
                        'notnull' => true,
                    ]);
                    \$table->setPrimaryKey(['message_id']);
                    \$table->addIndex(['message_name'], 'idx_dedup_message_name');
                    \$table->addIndex(['processed_at'], 'idx_dedup_processed_at');
                }

                public function down(Schema \$schema): void
                {
                    \$schema->dropTable('{$tableName}');
                }
            }

            PHP;
    }
}
