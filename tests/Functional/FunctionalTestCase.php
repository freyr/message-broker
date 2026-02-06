<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\DBAL\Connection;
use Freyr\Identity\Id;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Base test case for functional tests.
 *
 * Provides:
 * - Symfony kernel boot with TestKernel
 * - Database cleanup (truncate tables between tests)
 * - AMQP cleanup (purge queues between tests)
 * - AMQP connection pooling for performance
 * - Assertion helpers
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    // PERFORMANCE: Static connection pooling to avoid overhead (saves ~800ms-1.7s for 20 tests)
    private static ?AMQPStreamConnection $amqpConnection = null;
    private static bool $schemaInitialized = false;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Setup database schema once for entire functional test suite
        if (!self::$schemaInitialized) {
            self::setupDatabaseSchema();
            self::$schemaInitialized = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->cleanDatabase();
        $this->setupAmqp();
        $this->resetHandlers();
    }

    protected function tearDown(): void
    {
        // Defensive: Always reset handlers even if test failed
        // Prevents static state leakage between tests
        $this->resetHandlers();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$amqpConnection !== null) {
            self::$amqpConnection->close();
            self::$amqpConnection = null;
        }
        parent::tearDownAfterClass();
    }

    /**
     * Setup database schema for functional tests.
     *
     * Runs once when functional test suite starts (setUpBeforeClass).
     * Uses tests/Functional/schema.sql which includes all tables needed for testing.
     */
    private static function setupDatabaseSchema(): void
    {
        $schemaFile = __DIR__.'/schema.sql';
        $databaseUrl = isset($_ENV['DATABASE_URL']) && is_string($_ENV['DATABASE_URL'])
            ? $_ENV['DATABASE_URL']
            : 'mysql://messenger:messenger@127.0.0.1:3308/messenger_test';

        // Parse DATABASE_URL
        $parts = parse_url($databaseUrl);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 3306;
        $user = $parts['user'] ?? 'messenger';
        $pass = $parts['pass'] ?? 'messenger';
        $dbname = ltrim($parts['path'] ?? '/messenger_test', '/');

        // SAFETY CHECK: Only run on test databases
        if (!str_contains($dbname, '_test')) {
            throw new \RuntimeException(sprintf(
                'SAFETY CHECK FAILED: Database must contain "_test" in name. Got: %s',
                $dbname
            ));
        }

        try {
            // Wait for database to be ready (max 30 seconds)
            $maxRetries = 30;
            $retryDelay = 1;
            $pdo = null;

            for ($i = 0; $i < $maxRetries; ++$i) {
                try {
                    $pdo = new \PDO(
                        sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $dbname),
                        $user,
                        $pass,
                        [
                            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                            \PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                        ]
                    );
                    break;
                } catch (\PDOException $e) {
                    if ($i === $maxRetries - 1) {
                        throw new \RuntimeException(sprintf(
                            'Failed to connect to database after %d attempts: %s',
                            $maxRetries,
                            $e->getMessage()
                        ));
                    }
                    sleep($retryDelay);
                }
            }

            if ($pdo === null) {
                throw new \RuntimeException('Failed to establish database connection');
            }

            // Read and execute schema file
            $schema = file_get_contents($schemaFile);
            if ($schema === false) {
                throw new \RuntimeException('Failed to read schema file: '.$schemaFile);
            }

            // Execute entire schema file (PDO with MYSQL_ATTR_MULTI_STATEMENTS handles multiple statements)
            $pdo->exec($schema);

            // Verify tables were created
            $stmt = $pdo->query("SHOW TABLES LIKE 'message_broker_deduplication'");
            if ($stmt === false || $stmt->fetch() === false) {
                throw new \RuntimeException('Schema applied but message_broker_deduplication table not found');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Failed to setup database schema: %s', $e->getMessage()), previous: $e);
        }
    }

    private function cleanDatabase(): void
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        // SAFETY CHECK: Prevent accidental test-against-production scenarios
        $params = $connection->getParams();
        if (!str_contains($params['dbname'] ?? '', '_test')) {
            throw new \RuntimeException(
                'Safety check failed: Database must contain "_test" in name. Got: '.($params['dbname'] ?? 'unknown')
            );
        }

        // Truncate tables (order matters due to foreign keys if any)
        // All tables may not exist yet - check before truncating
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        $schemaManager = $connection->createSchemaManager();

        // Application-managed deduplication table
        if ($schemaManager->tablesExist(['message_broker_deduplication'])) {
            $connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');
        }

        // Auto-managed messenger tables (created by Symfony on first use)
        if ($schemaManager->tablesExist(['messenger_outbox'])) {
            $connection->executeStatement('TRUNCATE TABLE messenger_outbox');
        }
        if ($schemaManager->tablesExist(['messenger_messages'])) {
            $connection->executeStatement('TRUNCATE TABLE messenger_messages');
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected static function getAmqpConnection(): AMQPStreamConnection
    {
        if (self::$amqpConnection === null) {
            $dsn = isset($_ENV['MESSENGER_AMQP_DSN']) && is_string($_ENV['MESSENGER_AMQP_DSN'])
                ? $_ENV['MESSENGER_AMQP_DSN']
                : 'amqp://guest:guest@127.0.0.1:5673/%2f';
            $parts = parse_url($dsn);

            // Decode vhost from URL path (%2f -> /)
            $vhost = isset($parts['path']) ? urldecode(ltrim($parts['path'], '/')) : '/';

            self::$amqpConnection = new AMQPStreamConnection(
                $parts['host'] ?? '127.0.0.1',
                (int) ($parts['port'] ?? 5672),
                $parts['user'] ?? 'guest',
                $parts['pass'] ?? 'guest',
                $vhost
            );
        }

        return self::$amqpConnection;
    }

    private function setupAmqp(): void
    {
        try {
            $channel = self::getAmqpConnection()->channel();

            // Declare exchange
            $channel->exchange_declare('test_events', 'topic', false, true, false);

            // Define queues with their routing keys
            $queueBindings = [
                'test.event.sent' => ['test.event.sent'],      // TestEvent queue
                'test.order.placed' => ['test.order.placed'],  // OrderPlaced queue
                'test_inbox' => [],                            // Inbox test queue (no binding needed)
                'failed' => [],                                // Failed messages queue (no binding needed)
            ];

            foreach ($queueBindings as $queueName => $routingKeys) {
                // Declare queue
                $channel->queue_declare($queueName, false, true, false, false);

                // Bind to exchange with routing keys
                foreach ($routingKeys as $routingKey) {
                    $channel->queue_bind($queueName, 'test_events', $routingKey);
                }

                // Purge existing messages
                $channel->queue_purge($queueName);
            }

            $channel->close();
        } catch (\Exception $e) {
            // CRITICAL: Inbox tests MUST fail if AMQP is unavailable
            // Prevents false positives when RabbitMQ is down
            if (str_contains(static::class, 'Inbox')) {
                throw new \RuntimeException(
                    'AMQP setup failed for inbox test. RabbitMQ must be running: '.$e->getMessage(),
                    previous: $e
                );
            }

            // Outbox tests can continue without AMQP (they only test database storage)
            // Log the warning but don't fail
            error_log('AMQP unavailable for outbox test: '.$e->getMessage());
        }
    }

    // Assertion Helpers

    /**
     * @return array{body: mixed, headers: AMQPTable, envelope: AMQPMessage}
     */
    protected function assertMessageInQueue(string $queueName): array
    {
        $channel = self::getAmqpConnection()->channel();

        $message = $channel->basic_get($queueName);

        $channel->close();

        if ($message === null) {
            $this->fail("No message found in queue '{$queueName}'");
        }

        $body = json_decode($message->body, true);

        /** @var AMQPTable $headers */
        $headers = $message->get_properties()['application_headers'] ?? new AMQPTable();

        return [
            'body' => $body,
            'headers' => $headers,
            'envelope' => $message,
        ];
    }

    protected function assertQueueEmpty(string $queueName): void
    {
        $channel = self::getAmqpConnection()->channel();

        $message = $channel->basic_get($queueName);

        $channel->close();

        $this->assertNull($message, "Queue '{$queueName}' should be empty but contains messages");
    }

    // AMQP Helper Methods

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $body
     */
    protected function publishToAmqp(string $queue, array $headers, array $body): void
    {
        $channel = self::getAmqpConnection()->channel();

        $channel->queue_declare($queue, false, true, false, false);

        $message = new AMQPMessage(
            (string) json_encode($body),
            [
                'application_headers' => new AMQPTable($headers),
                'content_type' => 'application/json',
            ]
        );

        $channel->basic_publish($message, '', $queue);

        $channel->close();
    }

    /**
     * Publish a TestEvent to AMQP inbox queue with proper headers.
     *
     * @param Fixtures\TestEvent $event The event to publish
     * @param string|null $messageId Optional message ID (auto-generated if null)
     * @param string $queue Queue name (default: test_inbox)
     *
     * @return string The message ID (for assertions)
     */
    protected function publishTestEvent(
        Fixtures\TestEvent $event,
        ?string $messageId = null,
        string $queue = 'test_inbox',
    ): string {
        $messageId = $messageId ?? Id::new()->__toString();

        $this->publishToAmqp($queue, [
            'type' => 'test.event.sent',
            'X-Message-Id' => $messageId,
        ], [
            'id' => $event->id->__toString(),
            'name' => $event->name,
            'timestamp' => $event->timestamp->toIso8601String(),
        ]);

        return $messageId;
    }

    /**
     * Publish an OrderPlaced event to AMQP queue with proper headers.
     *
     * @param Fixtures\OrderPlaced $event The event to publish
     * @param string|null $messageId Optional message ID (auto-generated if null)
     * @param string $queue Queue name (default: test.order.placed)
     *
     * @return string The message ID (for assertions)
     */
    protected function publishOrderPlacedEvent(
        Fixtures\OrderPlaced $event,
        ?string $messageId = null,
        string $queue = 'test.order.placed',
    ): string {
        $messageId = $messageId ?? Id::new()->__toString();

        $this->publishToAmqp($queue, [
            'type' => 'test.order.placed',
            'X-Message-Id' => $messageId,
        ], [
            'orderId' => $event->orderId->__toString(),
            'customerId' => $event->customerId->__toString(),
            'totalAmount' => $event->totalAmount,
            'placedAt' => $event->placedAt->toIso8601String(),
        ]);

        return $messageId;
    }

    protected function consumeFromInbox(int $limit = 1): void
    {
        /** @var ReceiverInterface $receiver */
        $receiver = $this->getContainer()
            ->get('messenger.transport.amqp_test');
        /** @var MessageBusInterface $bus */
        $bus = $this->getContainer()
            ->get('messenger.default_bus');
        /** @var LoggerInterface $logger */
        $logger = $this->getContainer()
            ->get('logger');

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit, $logger));

        $worker = new Worker([
            'amqp_test' => $receiver,
        ], $bus, $eventDispatcher, $logger);
        $worker->run();
    }

    /**
     * Consume from inbox with manual transaction wrapping for testing rollback behavior.
     *
     * This simulates what would happen with doctrine_transaction middleware.
     */
    protected function processOutbox(int $limit = 1): void
    {
        /** @var ReceiverInterface $receiver */
        $receiver = $this->getContainer()
            ->get('messenger.transport.outbox');
        /** @var MessageBusInterface $bus */
        $bus = $this->getContainer()
            ->get('messenger.default_bus');
        /** @var LoggerInterface $logger */
        $logger = $this->getContainer()
            ->get('logger');

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit, $logger));

        $worker = new Worker([
            'outbox' => $receiver,
        ], $bus, $eventDispatcher, $logger);
        $worker->run();
    }

    // Inbox Test Helpers

    protected function resetHandlers(): void
    {
        Fixtures\TestEventHandler::reset();
        Fixtures\OrderPlacedHandler::reset();
        Fixtures\ThrowingTestEventHandler::reset();
    }

    protected function assertHandlerInvoked(string $handlerClass, int $expectedCount = 1): void
    {
        /** @var int $actualCount */
        $actualCount = $handlerClass::getInvocationCount();
        $this->assertEquals(
            $expectedCount,
            $actualCount,
            sprintf(
                'Expected handler %s to be invoked %d time(s), but was invoked %d time(s)',
                $handlerClass,
                $expectedCount,
                $actualCount
            )
        );
    }

    protected function assertDeduplicationEntryExists(string $messageId): void
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        // Convert UUID string to uppercase hex (no dashes) for binary comparison
        $messageIdHex = strtoupper(str_replace('-', '', $messageId));

        /** @var numeric-string $count */
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM message_broker_deduplication WHERE HEX(message_id) = ?',
            [$messageIdHex]
        );

        $this->assertGreaterThan(
            0,
            (int) $count,
            "Expected deduplication entry for message ID {$messageId} but none found"
        );
    }

    protected function getDeduplicationEntryCount(): int
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        /** @var numeric-string $count */
        $count = $connection->fetchOne('SELECT COUNT(*) FROM message_broker_deduplication');

        return (int) $count;
    }

    /**
     * Assert that no deduplication entry exists for given message ID.
     *
     * Used to verify transaction rollback after handler failure.
     */
    protected function assertNoDeduplicationEntryExists(string $messageId): void
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        // Convert UUID string to uppercase hex (no dashes) for binary comparison
        $messageIdHex = strtoupper(str_replace('-', '', $messageId));

        $result = $connection->fetchOne(
            'SELECT COUNT(*) FROM message_broker_deduplication WHERE HEX(message_id) = ?',
            [$messageIdHex]
        );

        $this->assertEquals(
            0,
            (int) (is_numeric($result) ? $result : 0),
            sprintf('Expected no deduplication entry for message ID %s, but found one', $messageId)
        );
    }

    /**
     * Assert that a message exists in the failed transport.
     *
     * @return array{body: array<mixed>, headers: array<mixed>}
     */
    protected function assertMessageInFailedTransport(string $messageClass): array
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        $result = $connection->fetchAssociative(
            "SELECT body, headers FROM messenger_messages WHERE queue_name = 'failed' ORDER BY id DESC LIMIT 1"
        );

        $this->assertIsArray($result, 'Expected message in failed transport, but failed transport is empty');

        $headersRaw = $result['headers'];
        $this->assertIsString($headersRaw);
        $headers = json_decode($headersRaw, true);
        $this->assertIsArray($headers);
        $this->assertArrayHasKey('X-Message-Class', $headers);

        $xMessageClass = $headers['X-Message-Class'];
        $this->assertIsArray($xMessageClass);
        $this->assertEquals($messageClass, $xMessageClass[0]);

        $bodyRaw = $result['body'];
        $this->assertIsString($bodyRaw);
        $body = json_decode($bodyRaw, true);
        $this->assertIsArray($body);

        return [
            'body' => $body,
            'headers' => $headers,
        ];
    }

    /**
     * Allowed tables for getTableRowCount() to prevent SQL injection.
     */
    private const ALLOWED_TABLES = ['message_broker_deduplication', 'messenger_outbox', 'messenger_messages'];

    /**
     * Get row count for a table (helper for quick assertions).
     *
     * Returns 0 if table doesn't exist (handles auto-managed tables that may not be created yet).
     */
    protected function getTableRowCount(string $table): int
    {
        if (!in_array($table, self::ALLOWED_TABLES, strict: true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid table name: "%s". Allowed tables: %s',
                $table,
                implode(', ', self::ALLOWED_TABLES)
            ));
        }

        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        // Check if table exists first (auto-managed tables may not be created yet)
        $schemaManager = $connection->createSchemaManager();
        if (!$schemaManager->tablesExist([$table])) {
            return 0;
        }

        /** @var numeric-string $count */
        $count = $connection->fetchOne("SELECT COUNT(*) FROM {$table}");

        return (int) $count;
    }

    /**
     * Publish a malformed AMQP message for testing error handling.
     *
     * @param array<string> $options Options: 'missingType', 'missingMessageId', 'invalidUuid', 'invalidJson'
     */
    protected function publishMalformedAmqpMessage(string $queue, array $options = []): void
    {
        $channel = self::getAmqpConnection()->channel();

        $headers = [];
        $body = '{"id": "01234567-89ab-cdef-0123-456789abcdef", "name": "test", "timestamp": "2026-01-30T12:00:00+00:00"}';

        // Apply malformation options
        if (!in_array('missingType', $options, true)) {
            $headers['type'] = 'test.event.sent';
        }

        if (!in_array('missingMessageId', $options, true)) {
            if (in_array('invalidUuid', $options, true)) {
                $headers['X-Message-Id'] = 'not-a-uuid';
            } else {
                $headers['X-Message-Id'] = '01234567-89ab-cdef-0123-456789abcdef';
            }
        }

        if (in_array('invalidJson', $options, true)) {
            $body = '{invalid json';
        }

        $message = new AMQPMessage($body, [
            'content_type' => 'application/json',
            'application_headers' => new AMQPTable($headers),
        ]);

        $channel->basic_publish($message, '', $queue);
        $channel->close();
    }
}
