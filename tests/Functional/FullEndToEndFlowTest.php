<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Application\Kernel;
use Freyr\MessageBroker\Tests\Fixtures\Consumer\OrderPlacedMessage;
use Freyr\MessageBroker\Tests\Fixtures\Publisher\OrderPlacedEvent;
use Freyr\MessageBroker\Tests\Functional\Handler\TestMessageCollector;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage as PhpAmqpMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Full end-to-end test: Outbox → RabbitMQ → Inbox → Handler
 *
 * This test validates the complete message flow through real infrastructure:
 * 1. Event dispatched to outbox database table
 * 2. Outbox consumed and published to RabbitMQ
 * 3. Message consumed from RabbitMQ and stored in inbox
 * 4. Inbox consumed and message delivered to handler
 */
class FullEndToEndFlowTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private TestMessageCollector $messageCollector;
    private string $testQueueName = 'test.e2e.queue';
    private string $testExchangeName = 'test.e2e.exchange';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->messageBus = $container->get('messenger.default_bus');
        $this->messageCollector = $container->get(TestMessageCollector::class);
        $this->messageCollector->clear();

        // Setup database tables
        $this->setupDatabase();

        // Setup RabbitMQ test queue
        $this->setupRabbitMQ();
    }

    protected function tearDown(): void
    {
        // Cleanup RabbitMQ test queue
        $this->cleanupRabbitMQ();

        parent::tearDown();
    }

    private function setupDatabase(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        // Drop and recreate tables
        $tables = ['messenger_outbox', 'messenger_inbox', 'messenger_messages'];
        foreach ($tables as $table) {
            try {
                $connection->executeStatement("DROP TABLE IF EXISTS {$table}");
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Create outbox table with binary UUID v7
        $connection->executeStatement("
            CREATE TABLE messenger_outbox (
                id BINARY(16) NOT NULL PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT 'outbox',
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create inbox table with binary UUID v7
        $connection->executeStatement("
            CREATE TABLE messenger_inbox (
                id BINARY(16) NOT NULL PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT 'inbox',
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create failed messages table
        $connection->executeStatement("
            CREATE TABLE messenger_messages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function setupRabbitMQ(): void
    {
        $connection = new AMQPStreamConnection('rabbitmq', 5672, 'guest', 'guest');
        $channel = $connection->channel();

        // Declare exchange and queue for testing
        $channel->exchange_declare($this->testExchangeName, 'topic', false, true, false);
        $channel->queue_declare($this->testQueueName, false, true, false, false);
        $channel->queue_bind($this->testQueueName, $this->testExchangeName, 'order.placed');

        $channel->close();
        $connection->close();
    }

    private function cleanupRabbitMQ(): void
    {
        try {
            $connection = new AMQPStreamConnection('rabbitmq', 5672, 'guest', 'guest');
            $channel = $connection->channel();

            $channel->queue_delete($this->testQueueName);
            $channel->exchange_delete($this->testExchangeName);

            $channel->close();
            $connection->close();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    public function testFullEndToEndFlow(): void
    {
        // Step 1: Dispatch event to outbox
        $messageId = Id::new();
        $orderId = Id::new();
        $customerId = Id::new();

        $event = new OrderPlacedEvent(
            messageId: $messageId,
            orderId: $orderId,
            customerId: $customerId,
            amount: 99.99,
            placedAt: \Carbon\CarbonImmutable::now()
        );

        $this->messageBus->dispatch($event);

        // Verify event is in outbox
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $outboxCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND delivered_at IS NULL"
        );
        $this->assertEquals(1, $outboxCount, 'Event should be stored in outbox');

        // Step 2: Consume from outbox and publish to RabbitMQ
        $outboxTransport = self::getContainer()->get('messenger.transport.outbox');
        $envelopes = $outboxTransport->get();
        $this->assertNotEmpty($envelopes, 'Should have message in outbox');

        // Manually publish to RabbitMQ (simulating OutboxToAmqpBridge)
        $envelope = $envelopes[0];
        $amqpConnection = new AMQPStreamConnection('rabbitmq', 5672, 'guest', 'guest');
        $channel = $amqpConnection->channel();

        // Serialize the message
        $serializer = self::getContainer()->get('Freyr\MessageBroker\Outbox\Serializer\OutboxSerializer');
        $encoded = $serializer->encode($envelope);
        $body = $encoded['body'];

        // Publish to RabbitMQ
        $amqpMessage = new PhpAmqpMessage($body, [
            'delivery_mode' => PhpAmqpMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => (string) $messageId,
        ]);
        $channel->basic_publish($amqpMessage, $this->testExchangeName, 'order.placed');

        // ACK the outbox message
        $outboxTransport->ack($envelope);

        $channel->close();
        $amqpConnection->close();

        // Give RabbitMQ a moment to process
        usleep(100000); // 100ms

        // Step 3: Verify message is in RabbitMQ
        $amqpConnection = new AMQPStreamConnection('rabbitmq', 5672, 'guest', 'guest');
        $channel = $amqpConnection->channel();

        $messageFromQueue = $channel->basic_get($this->testQueueName);
        $this->assertNotNull($messageFromQueue, 'Message should be in RabbitMQ queue');

        // Step 4: Simulate AmqpInboxIngestCommand consuming from RabbitMQ
        $amqpBody = $messageFromQueue->getBody();
        $decoded = json_decode($amqpBody, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('order.placed', $decoded['message_name']);
        $this->assertEquals((string) $messageId, $decoded['message_id']);

        // The AMQP message has outbox format, we need to transform it to inbox format
        // In production, AmqpInboxIngestCommand does this transformation
        // Extract the actual payload data
        $payload = $decoded['payload'];

        // Create InboxEventMessage with the payload in inbox format
        $inboxMessage = new \Freyr\MessageBroker\Inbox\Message\InboxEventMessage(
            messageName: $decoded['message_name'],
            payload: $payload, // This is already the event data
            messageId: $decoded['message_id'],
            sourceQueue: $this->testQueueName
        );

        $this->messageBus->dispatch($inboxMessage, [
            new \Symfony\Component\Messenger\Stamp\TransportNamesStamp(['inbox']),
            new \Freyr\MessageBroker\Inbox\Stamp\MessageNameStamp($decoded['message_name']),
            new \Freyr\MessageBroker\Inbox\Stamp\MessageIdStamp($decoded['message_id']),
            new \Freyr\MessageBroker\Inbox\Stamp\SourceQueueStamp($this->testQueueName),
        ]);

        // ACK RabbitMQ message
        $channel->basic_ack($messageFromQueue->getDeliveryTag());
        $channel->close();
        $amqpConnection->close();

        // Verify message is in inbox
        $inboxCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_inbox WHERE queue_name = 'inbox' AND delivered_at IS NULL"
        );
        $this->assertEquals(1, $inboxCount, 'Message should be stored in inbox');

        // Step 5: Consume from inbox and verify handler receives it
        $inboxTransport = self::getContainer()->get('messenger.transport.inbox');
        $inboxEnvelopes = $inboxTransport->get();
        $this->assertNotEmpty($inboxEnvelopes, 'Should have message in inbox transport');

        // Process the message
        foreach ($inboxEnvelopes as $inboxEnvelope) {
            $this->messageBus->dispatch($inboxEnvelope);
            $inboxTransport->ack($inboxEnvelope);
        }

        // Step 6: Verify handler received the message
        $this->assertTrue(
            $this->messageCollector->hasReceived(OrderPlacedMessage::class),
            'Handler should have received OrderPlacedMessage'
        );

        $messages = $this->messageCollector->getReceivedMessages();
        $this->assertCount(1, $messages);

        $receivedMessage = $messages[0];
        $this->assertInstanceOf(OrderPlacedMessage::class, $receivedMessage);
        $this->assertEquals($messageId, $receivedMessage->messageId);
        $this->assertEquals($orderId, $receivedMessage->orderId);
        $this->assertEquals($customerId, $receivedMessage->customerId);
        $this->assertEquals(99.99, $receivedMessage->amount);
    }
}
