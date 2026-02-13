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
 * 3. OutboxPublishingMiddleware processes outbox
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
        $messageBus = $this->getContainer()
            ->get(MessageBusInterface::class);
        $messageBus->dispatch($testEvent);

        // Then: Event is stored in messenger_outbox table
        /** @var Connection $connection */
        $connection = $this->getContainer()
            ->get('doctrine.dbal.default_connection');

        // Check row exists
        $count = $connection->fetchOne("SELECT COUNT(*) FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->assertEquals(1, $count, 'Expected 1 message in outbox');

        // Check body is JSON with semantic name
        $result = $connection->fetchAssociative(
            "SELECT body, headers FROM messenger_outbox WHERE queue_name = 'outbox' LIMIT 1"
        );

        $this->assertIsArray($result);

        // Body should be JSON (native serialiser)
        $this->assertIsString($result['body']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body, 'Body should be valid JSON');
        $this->assertEquals('integration-test-event', $body['name']);

        // Headers should contain FQN (native serialiser stores class name internally)
        $this->assertIsString($result['headers']);
        $headers = json_decode($result['headers'], true);
        $this->assertIsArray($headers);
        $this->assertEquals(TestEvent::class, $headers['type']);
    }

    public function testOutboxPublishesToAmqp(): void
    {
        // Given: An event in the outbox
        $testEvent = new TestEvent(id: Id::new(), name: 'publish-test-event', timestamp: CarbonImmutable::now());

        /** @var MessageBusInterface $messageBus */
        $messageBus = $this->getContainer()
            ->get(MessageBusInterface::class);
        $messageBus->dispatch($testEvent);

        // When: OutboxPublishingMiddleware processes the outbox (with LIMIT to prevent hanging)
        $this->processOutbox(limit: 1);

        // Then: Message is published to AMQP (routing key = semantic name = test.event.sent)
        // Messages are routed to queue bound with matching routing key
        $message = $this->assertMessageInQueue('test.event.sent');

        // And: Message has correct type header (semantic name)
        /** @var array<string, mixed> $headers */
        $headers = $message['headers']->getNativeData();
        $this->assertEquals('test.event.sent', $headers['type']);

        // And: Message has semantic X-Message-Id header (not FQN-based stamp header)
        $this->assertArrayHasKey('X-Message-Id', $headers);

        // And: X-Message-Id contains a valid UUID v7
        $this->assertIsString($headers['X-Message-Id']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $headers['X-Message-Id']
        );

        // And: Body contains event data (no messageId in payload)
        $body = $message['body'];
        $this->assertIsArray($body);
        $this->assertEquals('publish-test-event', $body['name']);
        $this->assertArrayNotHasKey('messageId', $body);
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
        $messageBus = $this->getContainer()
            ->get(MessageBusInterface::class);
        $messageBus->dispatch($testEvent);

        // When: Middleware processes and publishes (with LIMIT to prevent hanging)
        $this->processOutbox(limit: 1);

        // Then: Message in AMQP has correct structure (routing key = semantic name = test.order.placed)
        // With default exchange, message is routed to queue with same name as routing key
        $message = $this->assertMessageInQueue('test.order.placed');

        // Semantic name in type header
        /** @var array<string, mixed> $headers */
        $headers = $message['headers']->getNativeData();
        $this->assertEquals('test.order.placed', $headers['type']);

        // UUIDs are serialised as strings
        $body = $message['body'];
        $this->assertIsArray($body);
        $this->assertIsString($body['orderId']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $body['orderId']
        );

        // Timestamps are ISO 8601
        $this->assertIsString($body['placedAt']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $body['placedAt']
        );

        // Numeric values preserved
        $this->assertSame(99.99, $body['totalAmount']);
    }
}
