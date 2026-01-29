<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\DBAL\Connection;
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
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

        // SAFETY CHECK: Prevent accidental test-against-production scenarios
        $params = $connection->getParams();
        if (!str_contains($params['dbname'] ?? '', '_test')) {
            throw new \RuntimeException(
                'Safety check failed: Database must contain "_test" in name. ' .
                'Got: ' . ($params['dbname'] ?? 'unknown')
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
            // AMQP setup failure - not critical for outbox-only tests
        }
    }

    // Assertion Helpers

    protected function assertDatabaseHasRecord(string $table, array $criteria): void
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

        $qb = $connection->createQueryBuilder();
        $qb->select('COUNT(*) as count')
           ->from($table);

        foreach ($criteria as $column => $value) {
            // CRITICAL: Handle binary UUID columns with HEX comparison
            if ($column === 'message_id' && $value instanceof \Freyr\Identity\Id) {
                $qb->andWhere("HEX($column) = :$column")
                   ->setParameter($column, strtoupper(str_replace('-', '', $value->__toString())));
            } else {
                $qb->andWhere("$column = :$column")
                   ->setParameter($column, $value);
            }
        }

        $count = (int) $qb->executeQuery()->fetchOne();

        $this->assertGreaterThan(0, $count,
            "Failed asserting that table '$table' contains a record matching criteria.");
    }

    protected function assertMessageInQueue(string $queueName): ?array
    {
        $channel = self::getAmqpConnection()->channel();

        $message = $channel->basic_get($queueName);

        $channel->close();

        if ($message === null) {
            $this->fail("No message found in queue '$queueName'");
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

        $this->assertNull($message, "Queue '$queueName' should be empty but contains messages");
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

    protected function consumeFromInbox(int $limit = 1): void
    {
        $receiver = $this->getContainer()->get('messenger.transport.amqp_test');
        $bus = $this->getContainer()->get('messenger.default_bus');

        // Create a custom event dispatcher with StopWorkerOnMessageLimitListener
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit, $this->getContainer()->get('logger')));

        $worker = new Worker(
            ['amqp_test' => $receiver],
            $bus,
            $eventDispatcher,
            $this->getContainer()->get('logger')
        );

        // Stop after processing N messages (handled by StopWorkerOnMessageLimitListener)
        // Handler cleanup is done in tearDown()
        $worker->run();
    }

    protected function processOutbox(int $limit = 1): void
    {
        $receiver = $this->getContainer()->get('messenger.transport.outbox');
        $bus = $this->getContainer()->get('messenger.default_bus');

        // Create a custom event dispatcher with StopWorkerOnMessageLimitListener
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit, $this->getContainer()->get('logger')));

        $worker = new Worker(
            ['outbox' => $receiver],
            $bus,
            $eventDispatcher,
            $this->getContainer()->get('logger')
        );

        // Stop after processing N messages (handled by StopWorkerOnMessageLimitListener)
        // Handler cleanup is done in tearDown()
        $worker->run();
    }
}
