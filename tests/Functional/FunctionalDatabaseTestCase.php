<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Freyr\MessageBroker\Doctrine\Type\IdType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base class for functional tests requiring a real database connection (MySQL or PostgreSQL).
 *
 * Provides:
 * - DBAL connection from DATABASE_URL env var
 * - Safety check: database name must contain '_test'
 * - Schema setup via DBAL Schema API in setUpBeforeClass (once per suite)
 * - TRUNCATE deduplication table in setUp (each test method)
 * - IdType registration (global singleton, guarded)
 */
abstract class FunctionalDatabaseTestCase extends TestCase
{
    private static bool $schemaInitialized = false;

    protected static Connection $connection;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!Type::hasType(IdType::NAME)) {
            Type::addType(IdType::NAME, IdType::class);
        }

        $databaseUrl = getenv('DATABASE_URL')
            ?: 'mysql://messenger:messenger@mysql:3306/messenger_test';

        $dsnParser = new DsnParser([
            'mysql' => 'pdo_mysql',
            'postgresql' => 'pdo_pgsql',
        ]);
        $params = $dsnParser->parse($databaseUrl);

        self::$connection = DriverManager::getConnection($params);

        $dbName = self::$connection->getDatabase();
        if ($dbName === null || !str_contains($dbName, '_test')) {
            throw new RuntimeException(sprintf(
                'SAFETY: Database name must contain "_test". Got: %s',
                $dbName ?? 'null'
            ));
        }

        if (!self::$schemaInitialized) {
            self::setupSchema();
            self::$schemaInitialized = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');
    }

    private static function setupSchema(): void
    {
        $maxRetries = 30;

        for ($i = 0; $i < $maxRetries; ++$i) {
            try {
                self::$connection->executeQuery('SELECT 1');
                break;
            } catch (\Exception $e) {
                if ($i === $maxRetries - 1) {
                    throw new RuntimeException(sprintf(
                        'Database not ready after %d attempts: %s',
                        $maxRetries,
                        $e->getMessage()
                    ));
                }
                sleep(1);
            }
        }

        $schemaManager = self::$connection->createSchemaManager();

        if ($schemaManager->tablesExist(['message_broker_deduplication'])) {
            self::$connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');

            return;
        }

        if (self::$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            // IdType uses BINARY(16) which PostgreSQL does not support (no BINARY type).
            // Deduplication table creation is skipped on PostgreSQL.
            // Tests requiring it will fail explicitly; tests that don't need it proceed.
            return;
        }

        $table = new Table('message_broker_deduplication');
        $table->addColumn('message_id', IdType::NAME, [
            'length' => 16,
        ]);
        $table->addColumn('message_name', Types::STRING, [
            'length' => 255,
        ]);
        $table->addColumn('processed_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['message_id']);
        $table->addIndex(['processed_at'], 'idx_dedup_processed_at');

        $schemaManager->createTable($table);

        if (!$schemaManager->tablesExist(['message_broker_deduplication'])) {
            throw new RuntimeException('Schema applied but message_broker_deduplication table not found');
        }
    }
}
