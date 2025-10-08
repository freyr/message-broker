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
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * End-to-end functional test for outbox-to-inbox message flow
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
        $this->messageBus = $container->get('messenger.default_bus');
        $this->messageCollector = $container->get(TestMessageCollector::class);

        // Clear any previous messages
        $this->messageCollector->clear();

        // Setup database tables
        $this->setupDatabase();
    }

    private function setupDatabase(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        // Drop and recreate messenger tables
        $tables = ['messenger_outbox', 'messenger_inbox', 'messenger_messages'];
        foreach ($tables as $table) {
            try {
                $connection->executeStatement("DROP TABLE IF EXISTS {$table}");
            } catch (\Exception $e) {
                // Ignore if table doesn't exist
            }
        }

        // Create outbox table with binary UUID v7 as primary key
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

        // Create inbox table
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

    public function testOutboxToInboxCompleteFlow(): void
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

        // Step 2: Consume from outbox (this would publish to AMQP in production)
        $outboxTransport = self::getContainer()->get('messenger.transport.outbox');
        $envelopes = $outboxTransport->get();
        $this->assertNotEmpty($envelopes, 'Should have message in outbox transport');

        // In real scenario, outbox bridge would publish to AMQP
        // For testing, we simulate AMQP delivery by directly creating the typed consumer message
        // (MessageNameSerializer would normally deserialize AMQP message to this)

        // Step 3: Simulate AMQP message received and deserialized to typed consumer message
        $consumerMessage = new OrderPlacedMessage(
            messageId: $messageId,
            orderId: $orderId,
            customerId: $customerId,
            amount: 99.99,
            placedAt: \Carbon\CarbonImmutable::now(),
        );

        // Dispatch with stamps (MessageNameSerializer would add these from headers)
        $this->messageBus->dispatch($consumerMessage, [
            new TransportNamesStamp(['inbox']),
            new MessageNameStamp('order.placed'),
            new MessageIdStamp((string) $messageId),
        ]);

        // Verify message is in inbox
        $inboxCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_inbox WHERE queue_name = 'inbox' AND delivered_at IS NULL"
        );
        $this->assertEquals(1, $inboxCount, 'Message should be stored in inbox');

        // Step 4: Consume from inbox and verify handler receives it
        $inboxTransport = self::getContainer()->get('messenger.transport.inbox');
        $inboxEnvelopes = $inboxTransport->get();
        $this->assertNotEmpty($inboxEnvelopes, 'Should have message in inbox transport');

        // Process the message
        foreach ($inboxEnvelopes as $envelope) {
            $this->messageBus->dispatch($envelope);
            $inboxTransport->ack($envelope);
        }

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
        $this->assertEquals($messageId, $receivedMessage->messageId);
        $this->assertEquals($orderId, $receivedMessage->orderId);
        $this->assertEquals(99.99, $receivedMessage->amount);
    }

    public function testInboxDeduplication(): void
    {
        $messageId = Id::new();
        $orderId = Id::new();
        $customerId = Id::new();

        // Create typed consumer message (simulates what MessageNameSerializer would create)
        $consumerMessage = new OrderPlacedMessage(
            messageId: $messageId,
            orderId: $orderId,
            customerId: $customerId,
            amount: 99.99,
            placedAt: \Carbon\CarbonImmutable::now(),
        );

        // Dispatch same message twice
        for ($i = 0; $i < 2; $i++) {
            $this->messageBus->dispatch($consumerMessage, [
                new TransportNamesStamp(['inbox']),
                new MessageIdStamp((string) $messageId),
                new MessageNameStamp('order.placed'),
            ]);
        }

        // Verify only one message is stored (deduplication)
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $inboxCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_inbox WHERE queue_name = 'inbox'"
        );
        $this->assertEquals(1, $inboxCount, 'Only one message should be stored due to deduplication');

        // Consume and verify handler receives it only once
        $inboxTransport = self::getContainer()->get('messenger.transport.inbox');
        $processedCount = 0;

        while ($envelopes = $inboxTransport->get()) {
            foreach ($envelopes as $envelope) {
                $this->messageBus->dispatch($envelope);
                $inboxTransport->ack($envelope);
                $processedCount++;
            }
        }

        $this->assertEquals(1, $processedCount, 'Should process only one message');
        $this->assertEquals(
            1,
            $this->messageCollector->countReceived(OrderPlacedMessage::class),
            'Handler should receive exactly one message'
        );
    }
}
