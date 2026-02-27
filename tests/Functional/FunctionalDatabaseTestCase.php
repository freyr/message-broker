<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Freyr\MessageBroker\Doctrine\Type\IdType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base class for functional tests requiring a real MySQL connection.
 *
 * Provides:
 * - DBAL connection from DATABASE_URL env var
 * - Safety check: database name must contain '_test'
 * - Schema setup via schema.sql in setUpBeforeClass (once per suite)
 * - TRUNCATE deduplication table in setUp (each test method)
 * - IdType registration (global singleton, guarded)
 */
abstract class FunctionalDatabaseTestCase extends TestCase
{
    private static bool $schemaInitialised = false;

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

        if (!self::$schemaInitialised) {
            self::setupSchema();
            self::$schemaInitialised = true;
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

        $schemaFile = __DIR__.'/schema.sql';
        $schema = file_get_contents($schemaFile);

        if ($schema === false) {
            throw new RuntimeException(sprintf('Failed to read schema file: %s', $schemaFile));
        }

        self::$connection->executeStatement($schema);

        // Verify critical table exists
        $result = self::$connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'message_broker_deduplication'"
        );

        if (!is_numeric($result) || (int) $result !== 1) {
            throw new RuntimeException('Schema applied but message_broker_deduplication table not found');
        }
    }
}
