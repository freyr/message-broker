<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\DBAL\Connection;
use Freyr\Identity\Id;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
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

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
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
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');
        $connection->executeStatement('TRUNCATE TABLE messenger_outbox');
        $connection->executeStatement('TRUNCATE TABLE messenger_messages');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected static function getAmqpConnection(): AMQPStreamConnection
    {
        if (self::$amqpConnection === null) {
            $dsn = $_ENV['MESSENGER_AMQP_DSN'] ?? 'amqp://guest:guest@127.0.0.1:5673/%2f';
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

    protected function assertMessageInQueue(string $queueName): ?array
    {
        $channel = self::getAmqpConnection()->channel();

        $message = $channel->basic_get($queueName);

        $channel->close();

        if ($message === null) {
            $this->fail("No message found in queue '{$queueName}'");
        }

        $body = json_decode($message->body, true);
        $headers = $message->get_properties()['application_headers'] ?? [];

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

    protected function publishToAmqp(string $queue, array $headers, array $body): void
    {
        $channel = self::getAmqpConnection()->channel();

        $channel->queue_declare($queue, false, true, false, false);

        $message = new AMQPMessage(
            json_encode($body),
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
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([[
                'messageId' => $messageId,
            ]]),
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
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([[
                'messageId' => $messageId,
            ]]),
        ], [
            'id' => $event->id->__toString(),
            'customerId' => $event->customerId->__toString(),
            'totalAmount' => $event->totalAmount,
            'placedAt' => $event->placedAt->toIso8601String(),
        ]);

        return $messageId;
    }

    protected function consumeFromInbox(int $limit = 1): void
    {
        $receiver = $this->getContainer()
            ->get('messenger.transport.amqp_test');
        $bus = $this->getContainer()
            ->get('messenger.default_bus');

        // Create a custom event dispatcher with StopWorkerOnMessageLimitListener
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(
            new StopWorkerOnMessageLimitListener($limit, $this->getContainer()->get('logger'))
        );

        $worker = new Worker(
            [
                'amqp_test' => $receiver,
            ],
            $bus,
            $eventDispatcher,
            $this->getContainer()
                ->get('logger')
        );

        // Stop after processing N messages (handled by StopWorkerOnMessageLimitListener)
        // Handler cleanup is done in tearDown()
        $worker->run();
    }

    /**
     * Consume from inbox with manual transaction wrapping for testing rollback behavior.
     *
     * This simulates what would happen with doctrine_transaction middleware.
     */
    protected function processOutbox(int $limit = 1): void
    {
        $receiver = $this->getContainer()
            ->get('messenger.transport.outbox');
        $bus = $this->getContainer()
            ->get('messenger.default_bus');

        // Create a custom event dispatcher with StopWorkerOnMessageLimitListener
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(
            new StopWorkerOnMessageLimitListener($limit, $this->getContainer()->get('logger'))
        );

        $worker = new Worker(
            [
                'outbox' => $receiver,
            ],
            $bus,
            $eventDispatcher,
            $this->getContainer()
                ->get('logger')
        );

        // Stop after processing N messages (handled by StopWorkerOnMessageLimitListener)
        // Handler cleanup is done in tearDown()
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
        $actualCount = $handlerClass::getInvocationCount();
        $this->assertEquals(
            $expectedCount,
            $actualCount,
            "Expected handler {$handlerClass} to be invoked {$expectedCount} time(s), but was invoked {$actualCount} time(s)"
        );
    }

    protected function assertDeduplicationEntryExists(string $messageId): void
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        // Convert UUID string to uppercase hex (no dashes) for binary comparison
        $messageIdHex = strtoupper(str_replace('-', '', $messageId));

        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM message_broker_deduplication WHERE HEX(message_id) = ?',
            [$messageIdHex]
        );

        $this->assertGreaterThan(0, $count, "Expected deduplication entry for message ID {$messageId} but none found");
    }

    protected function getDeduplicationEntryCount(): int
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM message_broker_deduplication');
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
            (int) $result,
            sprintf('Expected no deduplication entry for message ID %s, but found one', $messageId)
        );
    }

    /**
     * Assert that a message exists in the failed transport.
     *
     * @return array{body: array, headers: array}
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

        $headers = json_decode($result['headers'], true);
        $this->assertIsArray($headers);
        $this->assertArrayHasKey('X-Message-Class', $headers);
        $this->assertEquals($messageClass, $headers['X-Message-Class'][0]);

        $body = json_decode($result['body'], true);
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

        return (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Publish a malformed AMQP message for testing error handling.
     *
     * @param string $queue Queue name
     * @param array $options Options: 'missingType', 'missingMessageId', 'invalidUuid', 'invalidJson'
     */
    protected function publishMalformedAmqpMessage(string $queue, array $options = []): void
    {
        $channel = self::getAmqpConnection()->channel();

        $headers = [];
        $body = '{"id": "01234567-89ab-cdef-0123-456789abcdef", "name": "test", "timestamp": "2026-01-30T12:00:00+00:00"}';

        // Apply malformation options
        if (!in_array('missingType', $options)) {
            $headers['type'] = 'test.event.sent';
        }

        if (!in_array('missingMessageId', $options)) {
            if (in_array('invalidUuid', $options)) {
                $headers['X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp'] = json_encode([[
                    'messageId' => 'not-a-uuid',
                ]]);
            } else {
                $headers['X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp'] = json_encode([[
                    'messageId' => '01234567-89ab-cdef-0123-456789abcdef',
                ]]);
            }
        }

        if (in_array('invalidJson', $options)) {
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
