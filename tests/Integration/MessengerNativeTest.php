<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Integration;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\Serializer\TypedInboxSerializer;
use Freyr\MessageBroker\Inbox\Transport\DoctrineInboxConnection;
use Freyr\MessageBroker\Outbox\Serializer\OutboxEventSerializer;
use Freyr\MessageBroker\Outbox\Transport\DoctrineOutboxConnection;
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
 * Tests using Symfony Messenger's native contract:
 * - MessageBus for dispatching
 * - Worker for consuming
 * - Handlers for processing
 */
final class MessengerNativeTest extends IntegrationTestCase
{
    private DoctrineTransport $outboxTransport;
    private DoctrineTransport $inboxTransport;
    private MessageBus $bus;
    private array $handledMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->handledMessages = [];

        // Setup outbox transport
        $outboxConfig = Connection::buildConfiguration(
            'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        );
        $outboxConnection = new DoctrineOutboxConnection($outboxConfig, $this->getConnection());
        $this->outboxTransport = new DoctrineTransport($outboxConnection, new OutboxEventSerializer());

        // Setup inbox transport
        $inboxConfig = Connection::buildConfiguration(
            'inbox://default?table_name=messenger_inbox&queue_name=inbox'
        );
        $inboxConnection = new DoctrineInboxConnection($inboxConfig, $this->getConnection());
        $messageTypes = [
            'order.placed' => OrderPlacedMessage::class,
        ];
        $this->inboxTransport = new DoctrineTransport($inboxConnection, new TypedInboxSerializer($messageTypes));

        // Setup message bus with handlers
        $this->bus = $this->createMessageBus();
    }

    public function test_outbox_message_dispatched_via_bus_is_stored(): void
    {
        // Given
        $event = new OrderPlacedEvent(
            messageId: Id::new(),
            orderId: Id::new(),
            customerId: Id::new(),
            amount: 100.50,
            placedAt: CarbonImmutable::now()
        );

        // When - Dispatch via MessageBus
        $envelope = new Envelope($event);
        $this->outboxTransport->send($envelope);

        // Then - Message stored in outbox
        $count = $this->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_outbox');
        $this->assertEquals(1, $count);
    }

    public function test_outbox_worker_consumes_and_handles_message(): void
    {
        // Given - Message in outbox
        $event = new OrderPlacedEvent(
            messageId: Id::new(),
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
        $deliveredCount = $this->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM messenger_outbox WHERE delivered_at IS NOT NULL'
        );
        $this->assertEquals(1, $deliveredCount);
    }

    public function test_inbox_worker_consumes_and_handles_typed_message(): void
    {
        // Given - Message in inbox
        $messageId = Id::new();
        $orderId = Id::new();
        $customerId = Id::new();
        $placedAt = CarbonImmutable::now();

        $inboxMessage = [
            'message_name' => 'order.placed',
            'message_id' => $messageId->__toString(),
            'payload' => [
                'messageId' => $messageId->__toString(),
                'orderId' => $orderId->__toString(),
                'customerId' => $customerId->__toString(),
                'amount' => 100.50,
                'placedAt' => $placedAt->toIso8601String(),
            ],
        ];

        $body = json_encode($inboxMessage);
        $headers = [
            'message_name' => 'order.placed',
            'message_id' => $messageId->__toString(),
        ];

        // Directly send to inbox (bypassing transport abstraction)
        $inboxConfig = Connection::buildConfiguration(
            'inbox://default?table_name=messenger_inbox&queue_name=inbox'
        );
        $inboxConnection = new DoctrineInboxConnection($inboxConfig, $this->getConnection());
        $inboxConnection->send($body, $headers);

        // When - Consume message via transport and dispatch via bus
        $this->consumeOneMessageFromTransport($this->inboxTransport);

        // Then - Typed message was handled
        $this->assertCount(1, $this->handledMessages);
        $this->assertInstanceOf(OrderPlacedMessage::class, $this->handledMessages[0]);
        $this->assertEquals($orderId->__toString(), $this->handledMessages[0]->orderId->__toString());
        $this->assertEquals($customerId->__toString(), $this->handledMessages[0]->customerId->__toString());
        $this->assertEquals(100.50, $this->handledMessages[0]->amount);
    }

    public function test_multiple_messages_can_be_consumed_sequentially(): void
    {
        // Given - Multiple messages in outbox
        $events = [];
        for ($i = 0; $i < 3; $i++) {
            $event = new OrderPlacedEvent(
                messageId: Id::new(),
                orderId: Id::new(),
                customerId: Id::new(),
                amount: 100.00 + $i,
                placedAt: CarbonImmutable::now()
            );
            $events[] = $event;
            $this->outboxTransport->send(new Envelope($event));
        }

        // When - Consume all messages via transport and dispatch via bus
        for ($i = 0; $i < 3; $i++) {
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

        if (!empty($envelopes)) {
            $envelope = $envelopes[0];

            // Dispatch through MessageBus (native Messenger API)
            $this->bus->dispatch($envelope);

            // Acknowledge the message (native Messenger API)
            $transport->ack($envelope);
        }
    }
}
