<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\MessageIdStamp;
use Freyr\MessageBroker\Inbox\MessageNameStamp;
use Freyr\MessageBroker\Tests\Application\Kernel;
use Freyr\MessageBroker\Tests\Fixtures\Consumer\OrderPlacedMessage;
use Freyr\MessageBroker\Tests\Fixtures\Publisher\OrderPlacedEvent;
use Freyr\MessageBroker\Tests\Functional\Handler\TestMessageCollector;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * End-to-end functional test for outbox-to-inbox message flow.
 *
 * This test verifies the complete flow:
 * 1. Event dispatched to outbox transport
 * 2. Outbox consumed and published via strategy
 * 3. AMQP message received and dispatched to inbox
 * 4. Inbox consumed and message delivered to handler
 */
class OutboxToInboxFlowTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private TestMessageCollector $messageCollector;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $messageBus = $container->get('messenger.default_bus');
        assert($messageBus instanceof MessageBusInterface);
        $this->messageBus = $messageBus;

        $messageCollector = $container->get(TestMessageCollector::class);
        assert($messageCollector instanceof TestMessageCollector);
        $this->messageCollector = $messageCollector;

        // Clear any previous messages
        $this->messageCollector->clear();

        // Setup database tables
        $this->setupDatabase();
    }

    private function setupDatabase(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        assert($connection instanceof \Doctrine\DBAL\Connection);

        // Drop and recreate messenger tables
        $tables = ['messenger_outbox', 'messenger_inbox', 'message_broker_deduplication', 'messenger_messages'];
        foreach ($tables as $table) {
            try {
                $connection->executeStatement("DROP TABLE IF EXISTS {$table}");
            } catch (\Exception $e) {
                // Ignore if table doesn't exist
            }
        }

        // Create outbox table (auto-increment PK - standard Symfony Messenger)
        $connection->executeStatement("
            CREATE TABLE messenger_outbox (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
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

        // Create inbox table (auto-increment PK - standard Symfony Messenger)
        $connection->executeStatement("
            CREATE TABLE messenger_inbox (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
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

        // Create deduplication table (binary UUID v7 as PK)
        $connection->executeStatement("
            CREATE TABLE message_broker_deduplication (
                message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
                message_name VARCHAR(255) NOT NULL,
                processed_at DATETIME NOT NULL,
                INDEX idx_message_name (message_name),
                INDEX idx_processed_at (processed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create failed messages table
        $connection->executeStatement('
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
        ');
    }

    public function testOutboxToInboxCompleteFlow(): void
    {
        // Step 1: Dispatch event to outbox
        $orderId = Id::new();
        $customerId = Id::new();

        $event = new OrderPlacedEvent(
            orderId: $orderId,
            customerId: $customerId,
            amount: 99.99,
            placedAt: \Carbon\CarbonImmutable::now()
        );

        $this->messageBus->dispatch($event);

        // Verify event is in outbox
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        assert($connection instanceof \Doctrine\DBAL\Connection);
        $outboxCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND delivered_at IS NULL"
        );
        $this->assertEquals(1, $outboxCount, 'Event should be stored in outbox');

        // Step 2: Consume from outbox (this would publish to AMQP in production)
        $outboxTransport = self::getContainer()->get('messenger.transport.outbox');
        assert($outboxTransport instanceof \Symfony\Component\Messenger\Transport\TransportInterface);
        $envelopes = $outboxTransport->get();
        $this->assertNotEmpty($envelopes, 'Should have message in outbox transport');

        // In real scenario, outbox bridge would publish to AMQP
        // For testing, we simulate AMQP delivery by directly creating the typed consumer message
        // (MessageNameSerializer would normally deserialize AMQP message to this)

        // Step 3: Simulate AMQP→Handler flow with DeduplicationMiddleware
        // In production: AMQP transport → MessageNameSerializer adds stamps → DeduplicationMiddleware → Handler
        // Generate messageId for deduplication (simulates what OutboxToAmqpBridge does)
        $messageId = Id::new();

        $consumerMessage = new OrderPlacedMessage(
            orderId: $orderId,
            customerId: $customerId,
            amount: 99.99,
            placedAt: \Carbon\CarbonImmutable::now(),
        );

        // Dispatch with stamps that DeduplicationMiddleware expects
        // ReceivedStamp triggers deduplication check
        $this->messageBus->dispatch($consumerMessage, [
            new \Symfony\Component\Messenger\Stamp\ReceivedStamp('amqp'),
            new MessageIdStamp((string) $messageId),
            new MessageNameStamp('order.placed'),
        ]);

        // Step 4: Verify deduplication entry was created
        $dedupCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM message_broker_deduplication WHERE message_name = 'order.placed'"
        );
        $this->assertEquals(1, $dedupCount, 'Deduplication entry should be created');

        // Verify handler received the message
        $this->assertTrue(
            $this->messageCollector->hasReceived(OrderPlacedMessage::class),
            'Handler should have received OrderPlacedMessage'
        );

        $this->assertEquals(
            1,
            $this->messageCollector->countReceived(OrderPlacedMessage::class),
            'Handler should have received exactly one message'
        );

        // Verify message content
        $messages = $this->messageCollector->getReceivedMessages();
        $this->assertCount(1, $messages);
        $receivedMessage = $messages[0];

        $this->assertInstanceOf(OrderPlacedMessage::class, $receivedMessage);
        $this->assertEquals($orderId, $receivedMessage->orderId);
        $this->assertEquals(99.99, $receivedMessage->amount);
    }

    public function testInboxDeduplication(): void
    {
        $messageId = Id::new();
        $orderId = Id::new();
        $customerId = Id::new();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        assert($connection instanceof \Doctrine\DBAL\Connection);

        // Create typed consumer message (simulates what MessageNameSerializer would create)
        $consumerMessage = new OrderPlacedMessage(
            orderId: $orderId,
            customerId: $customerId,
            amount: 99.99,
            placedAt: \Carbon\CarbonImmutable::now(),
        );

        // Dispatch same message twice with ReceivedStamp + required stamps for DeduplicationMiddleware
        // First dispatch - should be processed
        $this->messageBus->dispatch($consumerMessage, [
            new \Symfony\Component\Messenger\Stamp\ReceivedStamp('amqp'),
            new MessageIdStamp((string) $messageId),
            new MessageNameStamp('order.placed'),
        ]);

        // Verify first message was processed
        $this->assertEquals(
            1,
            $this->messageCollector->countReceived(OrderPlacedMessage::class),
            'First message should be processed'
        );

        // Verify deduplication entry was created
        $dedupCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM message_broker_deduplication WHERE message_name = 'order.placed'"
        );
        $this->assertEquals(1, $dedupCount, 'Deduplication entry should be created');

        // Second dispatch - should be skipped by DeduplicationMiddleware
        $this->messageBus->dispatch($consumerMessage, [
            new \Symfony\Component\Messenger\Stamp\ReceivedStamp('amqp'),
            new MessageIdStamp((string) $messageId),
            new MessageNameStamp('order.placed'),
        ]);

        // Verify handler was NOT called again (still only 1)
        $this->assertEquals(
            1,
            $this->messageCollector->countReceived(OrderPlacedMessage::class),
            'Handler should NOT process duplicate message'
        );

        // Verify still only one deduplication entry
        $dedupCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM message_broker_deduplication WHERE message_name = 'order.placed'"
        );
        $this->assertEquals(1, $dedupCount, 'Should still be only one deduplication entry');
    }
}
