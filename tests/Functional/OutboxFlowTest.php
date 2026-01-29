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
        $this->assertDatabaseHasRecord('messenger_outbox', [
            'queue_name' => 'outbox',
        ]);

        // And: Body contains serialised event data
        /** @var Connection $connection */
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
        $result = $connection->fetchAssociative(
            "SELECT body FROM messenger_outbox WHERE queue_name = 'outbox'"
        );

        $this->assertIsArray($result);
        $body = json_decode($result['body'], true);
        $this->assertEquals('integration-test-event', $body['name']);
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

        // When: OutboxToAmqpBridge processes the outbox
        $this->processOutbox();

        // Then: Message is published to AMQP exchange
        $message = $this->assertMessageInQueue('outbox');

        // And: Message has correct type header (semantic name)
        $this->assertArrayHasKey('type', $message['headers']);
        $this->assertEquals('test.event.sent', $message['headers']->getNativeData()['type']);

        // And: Message has MessageIdStamp header
        $headers = $message['headers']->getNativeData();
        $this->assertArrayHasKey('X-Message-Stamp-MessageIdStamp', $headers);

        // And: Body contains event data (no messageId in payload)
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

        // When: Bridge processes and publishes
        $this->processOutbox();

        // Then: Message in AMQP has correct structure
        $message = $this->assertMessageInQueue('outbox');

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
