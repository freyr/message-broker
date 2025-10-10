<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Integration;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Serializer\MessageNameSerializer;
use Freyr\MessageBroker\Tests\Fixtures\Consumer\OrderPlacedMessage;
use Freyr\MessageBroker\Tests\Fixtures\Publisher\OrderPlacedEvent;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * Native Messenger Integration Test.
 *
 * Tests using Symfony Messenger's native components with MessageNameSerializer:
 * - Standard DoctrineTransport with auto-increment PK
 * - MessageBus for dispatching
 * - Worker pattern for consuming
 * - Handlers for processing
 */
final class MessengerNativeTest extends IntegrationTestCase
{
    private DoctrineTransport $outboxTransport;
    private DoctrineTransport $inboxTransport;
    private MessageBus $bus;

    /** @var array<object> */
    private array $handledMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->handledMessages = [];

        // Setup outbox transport with native Connection (auto-increment PK)
        // Outbox needs message type mapping for decode (consuming from outbox returns events)
        $outboxConfig = Connection::buildConfiguration(
            'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        );
        $outboxConnection = new Connection($outboxConfig, $this->getConnection());
        $outboxMessageTypes = [
            'order.placed' => OrderPlacedEvent::class,
        ];
        $this->outboxTransport = new DoctrineTransport($outboxConnection, new MessageNameSerializer(
            $outboxMessageTypes
        ));

        // Setup inbox transport with native Connection (auto-increment PK)
        $inboxConfig = Connection::buildConfiguration(
            'doctrine://default?table_name=messenger_inbox&queue_name=inbox'
        );
        $inboxConnection = new Connection($inboxConfig, $this->getConnection());
        $messageTypes = [
            'order.placed' => OrderPlacedMessage::class,
        ];
        $this->inboxTransport = new DoctrineTransport($inboxConnection, new MessageNameSerializer($messageTypes));

        // Setup message bus with handlers
        $this->bus = $this->createMessageBus();
    }

    public function testOutboxMessageDispatchedViaBusIsStored(): void
    {
        // Given
        $event = new OrderPlacedEvent(
            orderId: Id::new(),
            customerId: Id::new(),
            amount: 100.50,
            placedAt: CarbonImmutable::now()
        );

        // When - Dispatch via MessageBus
        $envelope = new Envelope($event);
        $this->outboxTransport->send($envelope);

        // Then - Message stored in outbox
        $count = $this->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM messenger_outbox');
        $this->assertEquals(1, $count);
    }

    public function testOutboxWorkerConsumesAndHandlesMessage(): void
    {
        // Given - Message in outbox
        $event = new OrderPlacedEvent(
            orderId: Id::new(),
            customerId: Id::new(),
            amount: 100.50,
            placedAt: CarbonImmutable::now()
        );

        $envelope = new Envelope($event);
        $this->outboxTransport->send($envelope);

        // When - Consume message via transport and dispatch via bus
        $this->consumeOneMessageFromTransport($this->outboxTransport);

        // Then - Message was handled
        $this->assertCount(1, $this->handledMessages);
        $this->assertInstanceOf(OrderPlacedEvent::class, $this->handledMessages[0]);
        $this->assertEquals($event->orderId->__toString(), $this->handledMessages[0]->orderId->__toString());

        // And - Message marked as delivered
        $deliveredCount = $this->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM messenger_outbox WHERE delivered_at IS NOT NULL');
        $this->assertEquals(1, $deliveredCount);
    }

    public function testSerializerEncodesAndDecodesConsumerMessages(): void
    {
        // Given - Consumer message is typically never encoded (only decoded from AMQP)
        // But we can test the serializer's encode/decode cycle for inbox messages
        $orderId = Id::new();
        $customerId = Id::new();
        $placedAt = CarbonImmutable::now();

        // Create a test event (with MessageName) instead of consumer message
        $event = new OrderPlacedEvent(
            orderId: $orderId,
            customerId: $customerId,
            amount: 100.50,
            placedAt: $placedAt,
        );

        // When - Send to inbox transport
        $envelope = new Envelope($event);
        $this->inboxTransport->send($envelope);

        // Verify message is stored
        $count = $this->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM messenger_inbox');
        $this->assertEquals(1, $count, 'Message should be stored in inbox');

        // Consume and verify it can be deserialized back
        $this->consumeOneMessageFromTransport($this->inboxTransport);

        // Then - Message was handled
        $this->assertCount(1, $this->handledMessages);
        // Note: When consumed from inbox with 'order.placed' type, it deserializes to OrderPlacedMessage
        $firstMessage = $this->handledMessages[0];
        $this->assertInstanceOf(OrderPlacedMessage::class, $firstMessage);
        $this->assertEquals($orderId->__toString(), $firstMessage->orderId->__toString());
        $this->assertEquals(100.50, $firstMessage->amount);
    }

    public function testMultipleMessagesCanBeConsumedSequentially(): void
    {
        // Given - Multiple messages in outbox
        $events = [];
        for ($i = 0; $i < 3; ++$i) {
            $event = new OrderPlacedEvent(
                orderId: Id::new(),
                customerId: Id::new(),
                amount: 100.00 + $i,
                placedAt: CarbonImmutable::now()
            );
            $events[] = $event;
            $this->outboxTransport->send(new Envelope($event));
        }

        // When - Consume all messages via transport and dispatch via bus
        for ($i = 0; $i < 3; ++$i) {
            $this->consumeOneMessageFromTransport($this->outboxTransport);
        }

        // Then - All messages handled
        $this->assertCount(3, $this->handledMessages);
        foreach ($this->handledMessages as $i => $message) {
            $this->assertInstanceOf(OrderPlacedEvent::class, $message);
            $this->assertEquals($events[$i]->orderId->__toString(), $message->orderId->__toString());
        }
    }

    /**
     * Create MessageBus with handlers that capture handled messages.
     */
    private function createMessageBus(): MessageBus
    {
        // Handler for OrderPlacedEvent (outbox)
        $orderPlacedEventHandler = function (OrderPlacedEvent $event): void {
            $this->handledMessages[] = $event;
        };

        // Handler for OrderPlacedMessage (inbox)
        $orderPlacedMessageHandler = function (OrderPlacedMessage $message): void {
            $this->handledMessages[] = $message;
        };

        $handlersLocator = new HandlersLocator([
            OrderPlacedEvent::class => [$orderPlacedEventHandler],
            OrderPlacedMessage::class => [$orderPlacedMessageHandler],
        ]);

        $middleware = new HandleMessageMiddleware($handlersLocator);

        return new MessageBus([$middleware]);
    }

    /**
     * Consume exactly one message from transport using native Messenger contract.
     * This simulates what the Worker does: get() → dispatch() → ack().
     */
    private function consumeOneMessageFromTransport(DoctrineTransport $transport): void
    {
        // Get one message from transport (native Messenger API)
        $envelopes = $transport->get();

        foreach ($envelopes as $envelope) {
            // Dispatch through MessageBus (native Messenger API)
            $this->bus->dispatch($envelope);

            // Acknowledge the message (native Messenger API)
            $transport->ack($envelope);

            // Process only one message
            break;
        }
    }
}
