<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Freyr\MessageBroker\Doctrine\Type\IdType;
use Freyr\MessageBroker\Serializer\Normalizer\CarbonImmutableNormalizer;
use Freyr\MessageBroker\Serializer\Normalizer\IdNormalizer;
use Freyr\MessageBroker\Tests\Fixtures\AmqpTestSetup;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Base Integration Test Case.
 *
 * Provides database and AMQP setup for integration tests.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static Connection $connection;
    protected static AMQPStreamConnection $amqpConnection;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Setup database connection
        self::$connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => 'mysql',
            'port' => 3306,
            'user' => 'messenger',
            'password' => 'messenger',
            'dbname' => 'messenger_test',
        ]);

        // Register custom Doctrine types
        if (! IdType::hasType('id_binary')) {
            IdType::addType('id_binary', IdType::class);
        }

        // Create database schema
        self::createDatabaseSchema();

        // Setup AMQP connection
        self::$amqpConnection = new AMQPStreamConnection(
            host: 'rabbitmq',
            port: 5672,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        // Setup AMQP infrastructure
        AmqpTestSetup::setup(self::$amqpConnection);
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up AMQP
        AmqpTestSetup::tearDown(self::$amqpConnection);
        self::$amqpConnection->close();

        // Drop database tables
        self::dropDatabaseSchema();

        // Close database connection
        self::$connection->close();

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clean tables before each test
        self::$connection->executeStatement('TRUNCATE TABLE messenger_outbox');
        self::$connection->executeStatement('TRUNCATE TABLE messenger_inbox');
        self::$connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');
        self::$connection->executeStatement('TRUNCATE TABLE messenger_messages');

        // Purge AMQP queue
        $channel = self::$amqpConnection->channel();
        $channel->queue_purge('test.inbox');
        $channel->close();
    }

    private static function createDatabaseSchema(): void
    {
        // Create outbox table (auto-increment PK - standard Symfony Messenger)
        self::$connection->executeStatement('
            CREATE TABLE IF NOT EXISTS messenger_outbox (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT "outbox",
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Create inbox table (auto-increment PK - standard Symfony Messenger)
        self::$connection->executeStatement('
            CREATE TABLE IF NOT EXISTS messenger_inbox (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT "inbox",
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Create deduplication table (binary UUID v7 PK for message_id)
        self::$connection->executeStatement('
            CREATE TABLE IF NOT EXISTS message_broker_deduplication (
                message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT "(DC2Type:id_binary)",
                message_name VARCHAR(255) NOT NULL,
                processed_at DATETIME NOT NULL,
                INDEX idx_message_name (message_name),
                INDEX idx_processed_at (processed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Create standard messenger_messages table (for failed messages)
        self::$connection->executeStatement('
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_name (queue_name),
                INDEX idx_available_at (available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    private static function dropDatabaseSchema(): void
    {
        self::$connection->executeStatement('DROP TABLE IF EXISTS messenger_outbox');
        self::$connection->executeStatement('DROP TABLE IF EXISTS messenger_inbox');
        self::$connection->executeStatement('DROP TABLE IF EXISTS message_broker_deduplication');
        self::$connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    }

    protected function getConnection(): Connection
    {
        return self::$connection;
    }

    protected function getAmqpConnection(): AMQPStreamConnection
    {
        return self::$amqpConnection;
    }

    /**
     * Creates a Symfony Serializer with all necessary normalizers for testing.
     */
    protected function createSerializer(): Serializer
    {
        $reflectionExtractor = new ReflectionExtractor();
        $propertyTypeExtractor = new PropertyInfoExtractor(typeExtractors: [$reflectionExtractor]);

        $normalizers = [
            new IdNormalizer(),
            new CarbonImmutableNormalizer(),
            new DateTimeNormalizer(),
            new ObjectNormalizer(
                propertyTypeExtractor: $propertyTypeExtractor,
                // Disable type enforcement to allow int->float coercion
                defaultContext: [
                    ObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true,
                ]
            ),
        ];

        return new Serializer($normalizers, []);
    }
}
