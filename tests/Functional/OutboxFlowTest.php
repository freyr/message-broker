<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Connection;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\Fixtures\OrderPlaced;
use Freyr\MessageBroker\Tests\Functional\Fixtures\TestEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Functional tests for Outbox Pattern.
 *
 * Tests complete outbox flow:
 * 1. Event dispatched to message bus
 * 2. Event stored in messenger_outbox table
 * 3. OutboxToAmqpBridge processes outbox
 * 4. Event published to AMQP with correct format
 */
final class OutboxFlowTest extends FunctionalTestCase
{
    public function testEventIsStoredInOutboxDatabase(): void
    {
        // Given: A test event
        $testEvent = new TestEvent(
            id: Id::new(),
            name: 'integration-test-event',
            timestamp: CarbonImmutable::now()
        );

        // When: Event is dispatched to message bus
        /** @var MessageBusInterface $messageBus */
        $messageBus = $this->getContainer()->get(MessageBusInterface::class);
        $messageBus->dispatch($testEvent);

        // Then: Event is stored in messenger_outbox table
        /** @var Connection $connection */
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

        // Check row exists
        $count = $connection->fetchOne("SELECT COUNT(*) FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->assertEquals(1, $count, 'Expected 1 message in outbox');

        // Check body is JSON with semantic name
        $result = $connection->fetchAssociative(
            "SELECT body, headers FROM messenger_outbox WHERE queue_name = 'outbox' LIMIT 1"
        );

        $this->assertIsArray($result);

        // Body should be JSON (OutboxSerializer)
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body, 'Body should be valid JSON');
        $this->assertEquals('integration-test-event', $body['name']);

        // Headers should contain semantic name
        $headers = json_decode($result['headers'], true);
        $this->assertIsArray($headers);
        $this->assertEquals('test.event.sent', $headers['type']);
    }

    public function testOutboxBridgePublishesToAmqp(): void
    {
        // Given: An event in the outbox
        $testEvent = new TestEvent(
            id: Id::new(),
            name: 'bridge-test-event',
            timestamp: CarbonImmutable::now()
        );

        /** @var MessageBusInterface $messageBus */
        $messageBus = $this->getContainer()->get(MessageBusInterface::class);
        $messageBus->dispatch($testEvent);

        // When: OutboxToAmqpBridge processes the outbox (with LIMIT to prevent hanging)
        $this->processOutbox(limit: 1);

        // Then: Message is published to AMQP (routing key = semantic name = test.event.sent)
        // Messages are routed to queue bound with matching routing key
        $message = $this->assertMessageInQueue('test.event.sent');

        // And: Message has correct type header (semantic name)
        $headers = $message['headers']->getNativeData();
        $this->assertEquals('test.event.sent', $headers['type']);

        // And: Message has MessageIdStamp header (full FQN in header key)
        $this->assertArrayHasKey('X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp', $headers);

        // And: MessageIdStamp contains a valid UUID v7
        $messageIdStamp = json_decode($headers['X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp'], true);
        $this->assertIsArray($messageIdStamp);
        $this->assertArrayHasKey('messageId', $messageIdStamp[0]);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $messageIdStamp[0]['messageId']
        );

        // And: Body contains event data (no messageId in payload)
        $this->assertIsArray($message['body']);
        $this->assertEquals('bridge-test-event', $message['body']['name']);
        $this->assertArrayNotHasKey('messageId', $message['body']);
    }

    public function testPublishedMessageHasCorrectFormat(): void
    {
        // Given: An event with value objects
        $testEvent = new OrderPlaced(
            orderId: Id::new(),
            customerId: Id::new(),
            totalAmount: 99.99,
            placedAt: CarbonImmutable::now()
        );

        /** @var MessageBusInterface $messageBus */
        $messageBus = $this->getContainer()->get(MessageBusInterface::class);
        $messageBus->dispatch($testEvent);

        // When: Bridge processes and publishes (with LIMIT to prevent hanging)
        $this->processOutbox(limit: 1);

        // Then: Message in AMQP has correct structure (routing key = semantic name = test.order.placed)
        // With default exchange, message is routed to queue with same name as routing key
        $message = $this->assertMessageInQueue('test.order.placed');

        // Semantic name in type header
        $headers = $message['headers']->getNativeData();
        $this->assertEquals('test.order.placed', $headers['type']);

        // UUIDs are serialised as strings
        $this->assertIsString($message['body']['orderId']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $message['body']['orderId']
        );

        // Timestamps are ISO 8601
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $message['body']['placedAt']
        );

        // Numeric values preserved
        $this->assertSame(99.99, $message['body']['totalAmount']);
    }
}
