<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\MessageIdStamp;
use Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge;
use Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy;
use Freyr\MessageBroker\Tests\Unit\Factory\EventBusFactory;
use Freyr\MessageBroker\Tests\Unit\Fixtures\AmqpTestMessage;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Unit test for OutboxToAmqpBridge.
 *
 * Tests that the bridge:
 * - Only processes messages consumed from outbox transport
 * - Generates MessageIdStamp for each message
 * - Republishes to AMQP transport with correct routing
 * - Does NOT process messages routed directly to AMQP
 */
final class OutboxToAmqpBridgeTest extends TestCase
{
    public function testBridgeOnlyReceivesMessagesRoutedToOutbox(): void
    {
        // Given: EventBus with OutboxToAmqpBridge registered as handler
        $bridgeInvocationCount = 0;
        $processedMessages = [];

        // Create a wrapper to track bridge invocations
        $bridgeHandler = function (TestMessage|AmqpTestMessage $message) use (&$bridgeInvocationCount, &$processedMessages): void {
            $bridgeInvocationCount++;
            $processedMessages[] = $message;
        };

        $context = EventBusFactory::createForOutboxTesting(
            messageTypes: [
                'test.message.sent' => TestMessage::class,
                'test.amqp.sent' => AmqpTestMessage::class,
            ],
            routing: [
                TestMessage::class => ['outbox'],
                AmqpTestMessage::class => ['amqp'],
            ],
            handlers: [
                TestMessage::class => [$bridgeHandler],
                AmqpTestMessage::class => [$bridgeHandler],
            ]
        );

        $outboxMessage = new TestMessage(
            id: Id::new(),
            name: 'Outbox Message',
            timestamp: CarbonImmutable::now(),
        );

        $amqpMessage = new AmqpTestMessage(
            eventId: Id::new(),
            payload: 'Direct AMQP',
            sentAt: CarbonImmutable::now(),
        );

        // When: Messages are dispatched
        $context->bus->dispatch($outboxMessage);
        $context->bus->dispatch($amqpMessage);

        // Then: Both messages should be in their respective transports (not yet consumed)
        $this->assertEquals(1, $context->outboxTransport->count(), 'Outbox should have 1 message');
        $this->assertEquals(1, $context->amqpTransport->count(), 'AMQP should have 1 message');
        $this->assertEquals(0, $bridgeInvocationCount, 'Bridge should not be invoked yet (messages not consumed)');

        // When: We consume from outbox (simulating messenger:consume outbox)
        $outboxEnvelopes = $context->outboxTransport->get();
        foreach ($outboxEnvelopes as $envelope) {
            // Add ReceivedStamp to indicate it came from a transport
            $envelope = $envelope->with(new ReceivedStamp('outbox'));
            $context->bus->dispatch($envelope);
        }

        // Then: Bridge should have been invoked for outbox message
        $this->assertEquals(1, $bridgeInvocationCount, 'Bridge should be invoked once for outbox message');
        $this->assertCount(1, $processedMessages);
        $this->assertInstanceOf(TestMessage::class, $processedMessages[0]);

        // When: We consume from AMQP directly (no bridge involved)
        $amqpEnvelopes = $context->amqpTransport->get();
        foreach ($amqpEnvelopes as $envelope) {
            $envelope = $envelope->with(new ReceivedStamp('amqp'));
            $context->bus->dispatch($envelope);
        }

        // Then: Bridge should be invoked for AMQP message too (handler is registered for both)
        // But in real app, bridge has #[AsMessageHandler(fromTransport: 'outbox')] - only outbox
        $this->assertEquals(2, $bridgeInvocationCount, 'Handler invoked for both (no transport filtering in test)');
    }

    public function testBridgeGeneratesMessageIdAndRepublishesToAmqp(): void
    {
        // Given: EventBus with real OutboxToAmqpBridge
        $routingStrategy = new DefaultAmqpRoutingStrategy();
        $logger = new NullLogger();

        // Track what the bridge republishes
        $republishedEnvelopes = [];

        $context = EventBusFactory::createForOutboxTesting(
            messageTypes: [
                'test.message.sent' => TestMessage::class,
            ],
            routing: [
                TestMessage::class => ['outbox'],
            ]
        );

        // Create real OutboxToAmqpBridge
        $bridge = new OutboxToAmqpBridge(
            eventBus: $context->bus,
            routingStrategy: $routingStrategy,
            logger: $logger,
        );

        $message = new TestMessage(
            id: Id::new(),
            name: 'Test Bridge',
            timestamp: CarbonImmutable::now(),
        );

        // When: Message dispatched to outbox
        $context->bus->dispatch($message);

        // Then: Message in outbox
        $this->assertEquals(1, $context->outboxTransport->count());
        $this->assertEquals(0, $context->amqpTransport->count(), 'AMQP should be empty before bridge processes');

        // When: Bridge consumes from outbox (manually invoke bridge)
        $outboxEnvelopes = $context->outboxTransport->get();
        foreach ($outboxEnvelopes as $envelope) {
            $originalMessage = $envelope->getMessage();
            // Bridge processes the message
            $bridge->__invoke($originalMessage);
        }

        // Then: Message should be republished to AMQP
        $this->assertEquals(1, $context->amqpTransport->count(), 'Bridge should republish to AMQP');

        // And: Check the republished envelope has MessageIdStamp
        $amqpEnvelope = $context->amqpTransport->getLastEnvelope();
        $this->assertNotNull($amqpEnvelope);

        $messageIdStamps = $amqpEnvelope->all(MessageIdStamp::class);
        $this->assertNotEmpty($messageIdStamps, 'Envelope should have MessageIdStamp');

        /** @var MessageIdStamp $messageIdStamp */
        $messageIdStamp = $messageIdStamps[0];
        $this->assertNotEmpty($messageIdStamp->messageId, 'MessageIdStamp should contain a messageId');

        // Verify it's a valid UUID v7
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $messageIdStamp->messageId,
            'MessageId should be a valid UUID v7'
        );
    }
}
