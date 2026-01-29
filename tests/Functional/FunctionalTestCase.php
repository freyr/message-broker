<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\DBAL\Connection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
        $this->cleanAmqp();
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

            self::$amqpConnection = new AMQPStreamConnection(
                $parts['host'] ?? '127.0.0.1',
                (int) ($parts['port'] ?? 5672),
                $parts['user'] ?? 'guest',
                $parts['pass'] ?? 'guest',
                trim($parts['path'] ?? '/', '/')
            );
        }
        return self::$amqpConnection;
    }

    private function cleanAmqp(): void
    {
        $channel = self::getAmqpConnection()->channel();

        // Purge test queues (create if not exists, then purge)
        $queuesToPurge = ['outbox', 'test_inbox', 'failed'];

        foreach ($queuesToPurge as $queueName) {
            try {
                $channel->queue_declare($queueName, false, true, false, false);
                $channel->queue_purge($queueName);
            } catch (\Exception $e) {
                // Queue might not exist yet, that's okay
            }
        }

        $channel->close();
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

        $worker = new Worker(
            ['amqp_test' => $receiver],
            $bus,
            $this->getContainer()->get('event_dispatcher'),
            $this->getContainer()->get('logger')
        );

        $worker->run([
            'limit' => $limit,
            'time-limit' => 5,
        ]);
    }

    protected function processOutbox(int $limit = 1): void
    {
        $receiver = $this->getContainer()->get('messenger.transport.outbox');
        $bus = $this->getContainer()->get('messenger.default_bus');

        $worker = new Worker(
            ['outbox' => $receiver],
            $bus,
            $this->getContainer()->get('event_dispatcher'),
            $this->getContainer()->get('logger')
        );

        $worker->run([
            'limit' => $limit,
            'time-limit' => 5,
        ]);
    }
}
