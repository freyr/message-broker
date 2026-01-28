<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\MessageIdStamp;
use Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge;
use Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy;
use Freyr\MessageBroker\Tests\Unit\Factory\EventBusFactory;
use Freyr\MessageBroker\Tests\Unit\Fixtures\Consumer\OrderPlacedMessage;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Unit test for complete inbox flow.
 *
 * Tests the full message flow:
 * 1. Publish to outbox transport
 * 2. OutboxToAmqpBridge consumes from outbox and republishes to AMQP
 * 3. Consume from AMQP with MessageNameSerializer (semantic name → FQN)
 * 4. DeduplicationMiddleware checks MessageIdStamp
 * 5. Handler receives typed message
 * 6. Duplicate messages are rejected
 */
final class InboxFlowTest extends TestCase
{
    public function testCompleteOutboxToInboxFlow(): void
    {
        // Given: Complete inbox flow setup
        $handledMessages = [];
        $handlerInvocationCount = 0;

        $handler = function (OrderPlacedMessage $message) use (&$handledMessages, &$handlerInvocationCount): void {
            $handledMessages[] = $message;
            ++$handlerInvocationCount;
        };

        $context = EventBusFactory::createForInboxFlowTesting(
            messageTypes: [
                'test.message.sent' => OrderPlacedMessage::class,
            ],
            handlers: [
                OrderPlacedMessage::class => [$handler],
            ],
            routing: [
                TestMessage::class => ['outbox'],
            ]
        );

        // Create OutboxToAmqpBridge
        $bridge = new OutboxToAmqpBridge(
            eventBus: $context->bus,
            routingStrategy: new DefaultAmqpRoutingStrategy(),
            logger: new NullLogger(),
        );

        $testId = Id::new();
        $testName = 'Test Order';
        $testTimestamp = CarbonImmutable::now();

        $message = new TestMessage(id: $testId, name: $testName, timestamp: $testTimestamp);

        // Step 1: Publish message to outbox
        $context->bus->dispatch($message);

        // Then: Message should be in outbox transport
        $this->assertEquals(1, $context->outboxTransport->count());
        $this->assertEquals(0, $context->amqpPublishTransport->count());
        $this->assertEquals(0, $handlerInvocationCount, 'Handler should not be invoked yet');

        // Step 2: OutboxToAmqpBridge consumes from outbox and republishes to AMQP
        $outboxEnvelopes = $context->outboxTransport->get();
        foreach ($outboxEnvelopes as $envelope) {
            $originalMessage = $envelope->getMessage();
            $bridge->__invoke($originalMessage);
        }

        // Then: Message should be in AMQP publish transport
        $this->assertEquals(1, $context->amqpPublishTransport->count(), 'Bridge should republish to AMQP');
        $this->assertEquals(0, $handlerInvocationCount, 'Handler should not be invoked yet');

        // Verify AMQP message has MessageIdStamp
        $amqpEnvelope = $context->amqpPublishTransport->getLastEnvelope();
        $this->assertNotNull($amqpEnvelope);
        $messageIdStamps = $amqpEnvelope->all(MessageIdStamp::class);
        $this->assertNotEmpty($messageIdStamps, 'AMQP message should have MessageIdStamp from bridge');

        // Step 3: Consume from AMQP (InboxSerializer deserializes)
        // Get the serialized message from AMQP publish transport (already has semantic name from OutboxSerializer)
        $serialized = $context->amqpPublishTransport->getLastSerialized();
        $this->assertNotNull($serialized, 'AMQP should have serialized message');

        // Deserialize with InboxSerializer (translates semantic name → OrderPlacedMessage)
        // Stamps (MessageIdStamp) are automatically restored from X-Message-Stamp-* headers
        $deserializedEnvelope = $context->inboxSerializer->decode($serialized);

        // Add ReceivedStamp (simulates transport consumption)
        $deserializedEnvelope = $deserializedEnvelope->with(new ReceivedStamp('amqp'));

        // Dispatch through bus (DeduplicationMiddleware → Handler)
        // DeduplicationMiddleware uses MessageIdStamp + getMessage()::class
        $context->bus->dispatch($deserializedEnvelope);

        // Then: Handler should have been invoked
        $this->assertEquals(1, $handlerInvocationCount, 'Handler should be invoked once');
        $this->assertCount(1, $handledMessages);

        // Verify handler received correct typed message
        $handledMessage = $handledMessages[0];
        $this->assertInstanceOf(OrderPlacedMessage::class, $handledMessage);
        $this->assertEquals($testId->__toString(), $handledMessage->id->__toString());
        $this->assertEquals($testName, $handledMessage->name);

        // Verify deduplication store tracked the message
        $this->assertEquals(1, $context->deduplicationStore->getProcessedCount());
        $this->assertEquals(0, $context->deduplicationStore->getDuplicateCount());
    }

    public function testDeduplicationPreventsDoubleProcessing(): void
    {
        // Given: Inbox flow setup
        $handlerInvocationCount = 0;

        $handler = function (OrderPlacedMessage $message) use (&$handlerInvocationCount): void {
            ++$handlerInvocationCount;
        };

        $context = EventBusFactory::createForInboxFlowTesting(
            messageTypes: [
                'test.message.sent' => OrderPlacedMessage::class,
            ],
            handlers: [
                OrderPlacedMessage::class => [$handler],
            ],
            routing: [
                TestMessage::class => ['outbox'],
            ]
        );

        $bridge = new OutboxToAmqpBridge(
            eventBus: $context->bus,
            routingStrategy: new DefaultAmqpRoutingStrategy(),
            logger: new NullLogger(),
        );

        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());

        // Step 1: Publish to outbox
        $context->bus->dispatch($message);

        // Step 2: Bridge republishes to AMQP
        $outboxEnvelopes = $context->outboxTransport->get();
        foreach ($outboxEnvelopes as $envelope) {
            $bridge->__invoke($envelope->getMessage());
        }

        // Get the AMQP envelope with MessageIdStamp
        $amqpEnvelope = $context->amqpPublishTransport->getLastEnvelope();
        $this->assertNotNull($amqpEnvelope);
        $messageIdStamp = $amqpEnvelope->last(MessageIdStamp::class);
        $this->assertNotNull($messageIdStamp);

        // Step 3: Consume from AMQP (first time) - deserialize to get OrderPlacedMessage
        $serialized = $context->amqpPublishTransport->getLastSerialized();
        $this->assertNotNull($serialized);
        $deserializedEnvelope = $context->inboxSerializer->decode($serialized);
        $deserializedEnvelope = $deserializedEnvelope->with(new ReceivedStamp('amqp'));

        $context->bus->dispatch($deserializedEnvelope);

        // Then: Handler invoked once
        $this->assertEquals(1, $handlerInvocationCount);
        $this->assertEquals(1, $context->deduplicationStore->getProcessedCount());
        $this->assertEquals(0, $context->deduplicationStore->getDuplicateCount());

        // When: Same message consumed again (simulate retry/duplicate)
        $context->bus->dispatch($deserializedEnvelope);

        // Then: Handler NOT invoked again (deduplicated)
        $this->assertEquals(1, $handlerInvocationCount, 'Handler should not be invoked for duplicate');
        $this->assertEquals(
            1,
            $context->deduplicationStore->getProcessedCount(),
            'Processed count should not increase'
        );
        $this->assertEquals(1, $context->deduplicationStore->getDuplicateCount(), 'Duplicate should be detected');
    }

    public function testMessageNameSerializerTranslatesSemanticNameToFqn(): void
    {
        // Given: Inbox flow setup
        $handledMessages = [];

        $handler = function (OrderPlacedMessage $message) use (&$handledMessages): void {
            $handledMessages[] = $message;
        };

        $context = EventBusFactory::createForInboxFlowTesting(
            messageTypes: [
                'test.message.sent' => OrderPlacedMessage::class,
            ],
            handlers: [
                OrderPlacedMessage::class => [$handler],
            ],
            routing: [
                TestMessage::class => ['outbox'],
            ]
        );

        $bridge = new OutboxToAmqpBridge(
            eventBus: $context->bus,
            routingStrategy: new DefaultAmqpRoutingStrategy(),
            logger: new NullLogger(),
        );

        $message = new TestMessage(id: Id::new(), name: 'Serialization Test', timestamp: CarbonImmutable::now());

        // When: Message published to outbox
        $context->bus->dispatch($message);

        // And: Bridge republishes to AMQP
        $outboxEnvelopes = $context->outboxTransport->get();
        foreach ($outboxEnvelopes as $envelope) {
            $bridge->__invoke($envelope->getMessage());
        }

        // Then: AMQP message should have semantic name in type header
        $amqpSerialized = $context->amqpPublishTransport->getLastSerialized();
        $this->assertNotNull($amqpSerialized);
        $this->assertEquals(
            'test.message.sent',
            $amqpSerialized['headers']['type'],
            'AMQP transport should have semantic name (from OutboxSerializer)'
        );

        // When: Message consumed from AMQP and deserialized
        $serialized = $context->amqpPublishTransport->getLastSerialized();
        $this->assertNotNull($serialized);

        // InboxSerializer translates 'test.message.sent' → OrderPlacedMessage::class
        $deserializedEnvelope = $context->inboxSerializer->decode($serialized);
        $deserializedEnvelope = $deserializedEnvelope->with(new ReceivedStamp('amqp'));

        $context->bus->dispatch($deserializedEnvelope);

        // Then: Handler should receive typed OrderPlacedMessage
        $this->assertCount(1, $handledMessages);
        $this->assertInstanceOf(OrderPlacedMessage::class, $handledMessages[0]);
    }

    public function testMessagesWithoutMessageIdStampPassThroughWithoutDeduplication(): void
    {
        // Given: Inbox flow setup
        $handlerInvocationCount = 0;

        $handler = function (OrderPlacedMessage $message) use (&$handlerInvocationCount): void {
            ++$handlerInvocationCount;
        };

        $context = EventBusFactory::createForInboxFlowTesting(
            messageTypes: [
                'test.message.sent' => OrderPlacedMessage::class,
            ],
            handlers: [
                OrderPlacedMessage::class => [$handler],
            ],
            routing: [
                TestMessage::class => ['outbox'],
            ]
        );

        // Create a message WITHOUT MessageIdStamp
        $message = new OrderPlacedMessage(id: Id::new(), name: 'No MessageId', timestamp: CarbonImmutable::now());

        // When: Message dispatched with ReceivedStamp but NO MessageIdStamp
        $envelope = new \Symfony\Component\Messenger\Envelope($message, [new ReceivedStamp('amqp')]);

        $context->bus->dispatch($envelope);

        // Then: Handler should be invoked (no deduplication)
        $this->assertEquals(1, $handlerInvocationCount, 'Handler should be invoked for message without MessageIdStamp');
        $this->assertEquals(
            0,
            $context->deduplicationStore->getProcessedCount(),
            'Deduplication should not track messages without MessageIdStamp'
        );

        // When: Same message dispatched again (without MessageIdStamp)
        $context->bus->dispatch($envelope);

        // Then: Handler invoked again (no deduplication check)
        $this->assertEquals(
            2,
            $handlerInvocationCount,
            'Handler should be invoked again - no deduplication without MessageIdStamp'
        );
        $this->assertEquals(0, $context->deduplicationStore->getProcessedCount(), 'Still no deduplication tracking');
        $this->assertEquals(0, $context->deduplicationStore->getDuplicateCount(), 'No duplicates detected');
    }
}
