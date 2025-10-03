<?php

declare(strict_types=1);

namespace Freyr\Messenger\Tests\Integration;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\Messenger\Inbox\Serializer\TypedInboxSerializer;
use Freyr\Messenger\Inbox\Transport\DoctrineInboxConnection;
use Freyr\Messenger\Outbox\Publishing\AmqpPublishingStrategy;
use Freyr\Messenger\Outbox\Publishing\PublishingStrategyRegistry;
use Freyr\Messenger\Outbox\Routing\DefaultAmqpRoutingStrategy;
use Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer;
use Freyr\Messenger\Outbox\Transport\DoctrineOutboxConnection;
use Freyr\Messenger\Tests\Fixtures\Consumer\OrderPlacedMessage;
use Freyr\Messenger\Tests\Fixtures\Consumer\SlaCalculationStartedMessage;
use Freyr\Messenger\Tests\Fixtures\Consumer\UserPremiumUpgradedMessage;
use Freyr\Messenger\Tests\Fixtures\Publisher\OrderPlacedEvent;
use Freyr\Messenger\Tests\Fixtures\Publisher\SlaCalculationStartedEvent;
use Freyr\Messenger\Tests\Fixtures\Publisher\UserPremiumUpgradedEvent;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection as AmqpConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * End-to-End Integration Test.
 *
 * Tests the complete flow:
 * 1. Outbox: Event → Outbox table
 * 2. Outbox Worker: Outbox table → AMQP
 * 3. AMQP: RabbitMQ queue
 * 4. Inbox Ingester: AMQP → Inbox table
 * 5. Inbox Worker: Inbox table → TypedMessage
 */
final class EndToEndTest extends IntegrationTestCase
{
    private OutboxEventSerializer $outboxSerializer;
    private TypedInboxSerializer $inboxSerializer;
    private DefaultAmqpRoutingStrategy $routingStrategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outboxSerializer = new OutboxEventSerializer();

        $messageTypes = [
            'order.placed' => OrderPlacedMessage::class,
            'sla.calculation.started' => SlaCalculationStartedMessage::class,
            'user.premium.upgraded' => UserPremiumUpgradedMessage::class,
        ];
        $this->inboxSerializer = new TypedInboxSerializer($messageTypes);
        $this->routingStrategy = new DefaultAmqpRoutingStrategy();
    }

    public function test_end_to_end_flow_order_placed(): void
    {
        // ========== STEP 1: Publisher - Dispatch event to outbox ==========
        $messageId = Id::new();
        $orderId = Id::new();
        $customerId = Id::new();
        $amount = 100.50;
        $placedAt = CarbonImmutable::now();

        $event = new OrderPlacedEvent(
            messageId: $messageId,
            orderId: $orderId,
            customerId: $customerId,
            amount: $amount,
            placedAt: $placedAt
        );

        // Save to outbox
        $outboxConfig = Connection::buildConfiguration(
            'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        );
        $outboxConnection = new DoctrineOutboxConnection($outboxConfig, $this->getConnection());
        $outboxTransport = new DoctrineTransport($outboxConnection, $this->outboxSerializer);

        $envelope = new Envelope($event);
        $outboxTransport->send($envelope);

        // Verify saved to outbox
        $outboxCount = $this->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_outbox');
        $this->assertEquals(1, $outboxCount);

        // ========== STEP 2: Outbox Worker - Publish to AMQP ==========
        // Simulate outbox worker consuming from outbox and publishing to AMQP
        $envelopes = $outboxTransport->get();
        $this->assertNotEmpty($envelopes);
        $outboxEnvelope = $envelopes[0];

        // Publish to AMQP manually (simulating AmqpPublishingStrategy)
        $exchange = $this->routingStrategy->getExchange($event, 'order.placed');
        $routingKey = $this->routingStrategy->getRoutingKey($event, 'order.placed');

        $this->publishToAmqp($outboxEnvelope, $exchange, $routingKey);

        // ========== STEP 3: Verify message in AMQP queue ==========
        $channel = $this->getAmqpConnection()->channel();
        $amqpMessage = $channel->basic_get('test.inbox');
        $this->assertNotNull($amqpMessage, 'Message should be in AMQP queue');

        $amqpBody = json_decode($amqpMessage->body, true);
        $this->assertEquals('order.placed', $amqpBody['message_name']);
        $this->assertEquals($messageId->__toString(), $amqpBody['message_id']);

        $channel->basic_ack($amqpMessage->getDeliveryTag());
        $channel->close();

        // ========== STEP 4: Inbox Ingester - Consume from AMQP to Inbox ==========
        // Simulate inbox ingester consuming from AMQP and saving to inbox
        $inboxConfig = Connection::buildConfiguration(
            'inbox://default?table_name=messenger_inbox&queue_name=inbox'
        );
        $inboxConnection = new DoctrineInboxConnection($inboxConfig, $this->getConnection());

        $inboxConnection->send(
            $amqpMessage->body,
            [
                'message_name' => $amqpBody['message_name'],
                'message_id' => $amqpBody['message_id'],
            ]
        );

        // Verify saved to inbox
        $inboxCount = $this->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_inbox');
        $this->assertEquals(1, $inboxCount);

        // ========== STEP 5: Inbox Worker - Deserialize to TypedMessage ==========
        $inboxTransport = new DoctrineTransport($inboxConnection, $this->inboxSerializer);
        $inboxEnvelopes = $inboxTransport->get();
        $this->assertNotEmpty($inboxEnvelopes);

        $inboxEnvelope = $inboxEnvelopes[0];
        $message = $inboxEnvelope->getMessage();

        // ========== ASSERT: Full round-trip successful ==========
        $this->assertInstanceOf(OrderPlacedMessage::class, $message);
        $this->assertEquals($orderId->__toString(), $message->orderId->__toString());
        $this->assertEquals($customerId->__toString(), $message->customerId->__toString());
        $this->assertEquals($amount, $message->amount);
        $this->assertEquals($placedAt->toIso8601String(), $message->placedAt->toIso8601String());
    }

    public function test_end_to_end_flow_with_custom_exchange(): void
    {
        // Given - Event with custom exchange (#[AmqpExchange('sla.events')])
        $messageId = Id::new();
        $event = new SlaCalculationStartedEvent(
            messageId: $messageId,
            slaId: Id::new(),
            ticketId: Id::new(),
            startedAt: CarbonImmutable::now()
        );

        // Step 1: Save to outbox
        $outboxConfig = Connection::buildConfiguration(
            'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        );
        $outboxConnection = new DoctrineOutboxConnection($outboxConfig, $this->getConnection());
        $outboxTransport = new DoctrineTransport($outboxConnection, $this->outboxSerializer);
        $outboxTransport->send(new Envelope($event));

        // Step 2: Publish to AMQP (custom exchange should be used)
        $envelope = $outboxTransport->get()[0];
        $exchange = $this->routingStrategy->getExchange($event, 'sla.calculation.started');
        $routingKey = $this->routingStrategy->getRoutingKey($event, 'sla.calculation.started');

        $this->assertEquals('sla.events', $exchange, 'Custom exchange should be used');
        $this->publishToAmqp($envelope, $exchange, $routingKey);

        // Step 3: Verify in AMQP queue
        $channel = $this->getAmqpConnection()->channel();
        $amqpMessage = $channel->basic_get('test.inbox');
        $this->assertNotNull($amqpMessage);

        $amqpBody = json_decode($amqpMessage->body, true);
        $this->assertEquals('sla.calculation.started', $amqpBody['message_name']);
        $this->assertEquals($messageId->__toString(), $amqpBody['message_id']);

        $channel->basic_ack($amqpMessage->getDeliveryTag());
        $channel->close();
    }

    public function test_deduplication_prevents_duplicate_inbox_entries(): void
    {
        // Given - Same event published twice
        $messageId = Id::new();
        $event = new OrderPlacedEvent(
            messageId: $messageId,
            orderId: Id::new(),
            customerId: Id::new(),
            amount: 100.50,
            placedAt: CarbonImmutable::now()
        );

        // Outbox transport
        $outboxConfig = Connection::buildConfiguration(
            'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        );
        $outboxConnection = new DoctrineOutboxConnection($outboxConfig, $this->getConnection());
        $outboxTransport = new DoctrineTransport($outboxConnection, $this->outboxSerializer);

        // Inbox connection
        $inboxConfig = Connection::buildConfiguration(
            'inbox://default?table_name=messenger_inbox&queue_name=inbox'
        );
        $inboxConnection = new DoctrineInboxConnection($inboxConfig, $this->getConnection());

        // When - Publish same event twice
        for ($i = 0; $i < 2; $i++) {
            $outboxTransport->send(new Envelope($event));
            $envelope = $outboxTransport->get()[0];

            // Publish to AMQP
            $exchange = $this->routingStrategy->getExchange($event, 'order.placed');
            $routingKey = $this->routingStrategy->getRoutingKey($event, 'order.placed');
            $this->publishToAmqp($envelope, $exchange, $routingKey);

            // Consume from AMQP to inbox
            $channel = $this->getAmqpConnection()->channel();
            $amqpMessage = $channel->basic_get('test.inbox');
            $amqpBody = json_decode($amqpMessage->body, true);

            $inboxConnection->send(
                $amqpMessage->body,
                [
                    'message_name' => $amqpBody['message_name'],
                    'message_id' => $amqpBody['message_id'],
                ]
            );

            $channel->basic_ack($amqpMessage->getDeliveryTag());
            $channel->close();

            // Clean outbox for next iteration
            $this->getConnection()->executeStatement('TRUNCATE TABLE messenger_outbox');
        }

        // Then - Only 1 message in inbox (duplicate ignored)
        $inboxCount = $this->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_inbox');
        $this->assertEquals(1, $inboxCount, 'Duplicate should be prevented by INSERT IGNORE');
    }

    /**
     * Helper: Publish envelope to AMQP (simulates AmqpPublishingStrategy).
     */
    private function publishToAmqp(Envelope $envelope, string $exchange, string $routingKey): void
    {
        $encoded = $this->outboxSerializer->encode($envelope);
        $body = $encoded['body'];

        $channel = $this->getAmqpConnection()->channel();
        $amqpMessage = new AMQPMessage($body, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $channel->basic_publish($amqpMessage, $exchange, $routingKey);
        $channel->close();
    }
}
