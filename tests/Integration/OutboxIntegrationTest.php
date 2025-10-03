<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Integration;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\Publishing\AmqpPublishingStrategy;
use Freyr\MessageBroker\Outbox\Publishing\PublishingStrategyRegistry;
use Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy;
use Freyr\MessageBroker\Outbox\Serializer\OutboxEventSerializer;
use Freyr\MessageBroker\Outbox\Transport\DoctrineOutboxConnection;
use Freyr\MessageBroker\Tests\Fixtures\Publisher\OrderPlacedEvent;
use Freyr\MessageBroker\Tests\Fixtures\Publisher\SlaCalculationStartedEvent;
use Freyr\MessageBroker\Tests\Fixtures\Publisher\UserPremiumUpgradedEvent;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;

/**
 * Outbox Integration Test.
 *
 * Tests:
 * 1. Events are saved to outbox table
 * 2. Events are serialized correctly with message_id
 * 3. Outbox worker publishes to AMQP
 * 4. Routing strategy (default and attribute overrides) works
 */
final class OutboxIntegrationTest extends IntegrationTestCase
{
    private DoctrineTransport $outboxTransport;
    private OutboxEventSerializer $serializer;
    private MessageBus $eventBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serializer = new OutboxEventSerializer();

        // Create outbox transport with custom connection
        $connection = Connection::buildConfiguration(
            'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        );
        $doctrineConnection = new DoctrineOutboxConnection($connection, $this->getConnection());
        $this->outboxTransport = new DoctrineTransport($doctrineConnection, $this->serializer);

        // Create simple event bus for dispatching to outbox
        $this->eventBus = new MessageBus();
    }

    public function test_event_is_saved_to_outbox_table(): void
    {
        // Given
        $event = new OrderPlacedEvent(
            messageId: Id::new(),
            orderId: Id::new(),
            customerId: Id::new(),
            amount: 100.50,
            placedAt: CarbonImmutable::now()
        );

        // When
        $envelope = new Envelope($event);
        $this->outboxTransport->send($envelope);

        // Then
        $result = $this->getConnection()->fetchAssociative(
            'SELECT * FROM messenger_outbox WHERE queue_name = ?',
            ['outbox']
        );

        $this->assertNotFalse($result, 'Event should be saved to outbox table');
        $this->assertIsArray($result);

        $body = json_decode($result['body'], true);
        $this->assertEquals('order.placed', $body['message_name']);
        $this->assertEquals($event->messageId->__toString(), $body['message_id']);
        $this->assertArrayHasKey('payload', $body);
    }

    public function test_message_id_is_validated_and_extracted(): void
    {
        // Given
        $messageId = Id::new();
        $event = new OrderPlacedEvent(
            messageId: $messageId,
            orderId: Id::new(),
            customerId: Id::new(),
            amount: 100.50,
            placedAt: CarbonImmutable::now()
        );

        // When
        $envelope = new Envelope($event);
        $encoded = $this->serializer->encode($envelope);

        // Then
        $this->assertArrayHasKey('body', $encoded);
        $this->assertArrayHasKey('headers', $encoded);

        $body = json_decode($encoded['body'], true);
        $this->assertEquals($messageId->__toString(), $body['message_id']);
        $this->assertEquals($messageId->__toString(), $encoded['headers']['message_id']);
    }

    public function test_routing_strategy_default_convention(): void
    {
        // Given
        $event = new OrderPlacedEvent(
            messageId: Id::new(),
            orderId: Id::new(),
            customerId: Id::new(),
            amount: 100.50,
            placedAt: CarbonImmutable::now()
        );

        $strategy = new DefaultAmqpRoutingStrategy();

        // When
        $exchange = $strategy->getExchange($event, 'order.placed');
        $routingKey = $strategy->getRoutingKey($event, 'order.placed');

        // Then
        $this->assertEquals('order.placed', $exchange, 'Exchange should be first 2 parts');
        $this->assertEquals('order.placed', $routingKey, 'Routing key should be full message name');
    }

    public function test_routing_strategy_with_exchange_override(): void
    {
        // Given - Event with #[AmqpExchange('sla.events')]
        $event = new SlaCalculationStartedEvent(
            messageId: Id::new(),
            slaId: Id::new(),
            ticketId: Id::new(),
            startedAt: CarbonImmutable::now()
        );

        $strategy = new DefaultAmqpRoutingStrategy();

        // When
        $exchange = $strategy->getExchange($event, 'sla.calculation.started');
        $routingKey = $strategy->getRoutingKey($event, 'sla.calculation.started');

        // Then
        $this->assertEquals('sla.events', $exchange, 'Exchange should be overridden by attribute');
        $this->assertEquals('sla.calculation.started', $routingKey, 'Routing key should be default');
    }

    public function test_routing_strategy_with_routing_key_override(): void
    {
        // Given - Event with #[AmqpRoutingKey('user.*.upgraded')]
        $event = new UserPremiumUpgradedEvent(
            messageId: Id::new(),
            userId: Id::new(),
            plan: 'enterprise',
            upgradedAt: CarbonImmutable::now()
        );

        $strategy = new DefaultAmqpRoutingStrategy();

        // When
        $exchange = $strategy->getExchange($event, 'user.premium.upgraded');
        $routingKey = $strategy->getRoutingKey($event, 'user.premium.upgraded');

        // Then
        $this->assertEquals('user.premium', $exchange, 'Exchange should be default (first 2 parts)');
        $this->assertEquals('user.*.upgraded', $routingKey, 'Routing key should be overridden by attribute');
    }

    public function test_multiple_events_can_be_saved_to_outbox(): void
    {
        // Given
        $events = [
            new OrderPlacedEvent(
                messageId: Id::new(),
                orderId: Id::new(),
                customerId: Id::new(),
                amount: 100.50,
                placedAt: CarbonImmutable::now()
            ),
            new SlaCalculationStartedEvent(
                messageId: Id::new(),
                slaId: Id::new(),
                ticketId: Id::new(),
                startedAt: CarbonImmutable::now()
            ),
            new UserPremiumUpgradedEvent(
                messageId: Id::new(),
                userId: Id::new(),
                plan: 'pro',
                upgradedAt: CarbonImmutable::now()
            ),
        ];

        // When
        foreach ($events as $event) {
            $envelope = new Envelope($event);
            $this->outboxTransport->send($envelope);
        }

        // Then
        $count = $this->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM messenger_outbox WHERE queue_name = ?',
            ['outbox']
        );

        $this->assertEquals(3, $count, 'All 3 events should be saved');
    }
}
