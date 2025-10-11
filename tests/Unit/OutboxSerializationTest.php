<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Unit\Factory\EventBusFactory;
use Freyr\MessageBroker\Tests\Unit\Fixtures\AmqpTestMessage;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for outbox message serialization.
 *
 * Tests that messages are correctly:
 * - Routed to outbox transport
 * - Serialized with semantic message names (not FQN)
 * - Formatted as valid JSON
 * - Do not contain messageId in payload (it's transport metadata)
 */
final class OutboxSerializationTest extends TestCase
{
    public function testMessageIsDispatchedToOutboxAndSerializedCorrectly(): void
    {
        // Given: EventBus configured for outbox testing with FQN routing
        $context = EventBusFactory::createForOutboxTesting(
            messageTypes: [
                'test.message.sent' => TestMessage::class,
            ],
            routing: [
                TestMessage::class => ['outbox'],
            ]
        );

        $testId = Id::new();
        $testName = 'Test Event';
        $testTimestamp = CarbonImmutable::now();

        $message = new TestMessage(
            id: $testId,
            name: $testName,
            timestamp: $testTimestamp,
        );

        // When: Message is dispatched to EventBus
        $context->bus->dispatch($message);

        // Then: Message should be in outbox transport
        $this->assertEquals(1, $context->outboxTransport->count(), 'Message should be sent to outbox transport');

        // And: Envelope should contain the message
        $envelope = $context->outboxTransport->getLastEnvelope();
        $this->assertNotNull($envelope, 'Outbox should have an envelope');
        $this->assertInstanceOf(TestMessage::class, $envelope->getMessage());

        // And: Serialized format should be correct
        $serialized = $context->outboxTransport->getLastSerialized();
        $this->assertNotNull($serialized, 'Should have serialized representation');

        // Check headers
        $this->assertArrayHasKey('headers', $serialized);
        $headers = $serialized['headers'];

        // Type header should contain semantic name (not FQN)
        $this->assertArrayHasKey('type', $headers);
        $this->assertEquals('test.message.sent', $headers['type'], 'Type header should contain semantic message name');
        $this->assertNotEquals(TestMessage::class, $headers['type'], 'Type header should NOT contain FQN');

        // Check body
        $this->assertArrayHasKey('body', $serialized);
        $body = $serialized['body'];
        $this->assertIsString($body, 'Body should be a string');

        // Body should be valid JSON
        $decodedBody = json_decode($body, true);
        $this->assertIsArray($decodedBody, 'Body should be valid JSON');

        // Body should contain business properties
        $this->assertArrayHasKey('id', $decodedBody, 'Body should contain id property');
        $this->assertArrayHasKey('name', $decodedBody, 'Body should contain name property');
        $this->assertArrayHasKey('timestamp', $decodedBody, 'Body should contain timestamp property');

        // Body should NOT contain messageId (it's transport metadata, not business data)
        $this->assertArrayNotHasKey('messageId', $decodedBody, 'Body should NOT contain messageId - it is transport metadata');

        // Verify property values
        $this->assertEquals($testId->__toString(), $decodedBody['id']);
        $this->assertEquals($testName, $decodedBody['name']);
        $this->assertEquals($testTimestamp->toIso8601String(), $decodedBody['timestamp']);
    }

    public function testMultipleMessagesAreSerializedIndependently(): void
    {
        // Given: EventBus configured for outbox testing with FQN routing
        $context = EventBusFactory::createForOutboxTesting(
            messageTypes: [
                'test.message.sent' => TestMessage::class,
            ],
            routing: [
                TestMessage::class => ['outbox'],
            ]
        );

        // When: Multiple messages are dispatched
        $message1 = new TestMessage(
            id: Id::new(),
            name: 'First Message',
            timestamp: CarbonImmutable::now(),
        );

        $message2 = new TestMessage(
            id: Id::new(),
            name: 'Second Message',
            timestamp: CarbonImmutable::now(),
        );

        $context->bus->dispatch($message1);
        $context->bus->dispatch($message2);

        // Then: Both messages should be in outbox
        $this->assertEquals(2, $context->outboxTransport->count(), 'Both messages should be sent to outbox');

        $envelopes = $context->outboxTransport->getSentEnvelopes();
        $this->assertCount(2, $envelopes);

        // Verify each has correct type
        foreach ($envelopes as $envelope) {
            $this->assertInstanceOf(TestMessage::class, $envelope->getMessage());
        }
    }

    public function testMessageRoutingToCorrectTransport(): void
    {
        // Given: EventBus with different routing for different messages
        $context = EventBusFactory::createForOutboxTesting(
            messageTypes: [
                'test.message.sent' => TestMessage::class,
                'test.amqp.sent' => AmqpTestMessage::class,
            ],
            routing: [
                TestMessage::class => ['outbox'],
                AmqpTestMessage::class => ['amqp'],
            ]
        );

        $outboxMessage = new TestMessage(
            id: Id::new(),
            name: 'Outbox Message',
            timestamp: CarbonImmutable::now(),
        );

        $amqpMessage = new AmqpTestMessage(
            eventId: Id::new(),
            payload: 'AMQP Payload',
            sentAt: CarbonImmutable::now(),
        );

        // When: Messages are dispatched
        $context->bus->dispatch($outboxMessage);
        $context->bus->dispatch($amqpMessage);

        // Then: TestMessage should be in outbox transport only
        $this->assertEquals(1, $context->outboxTransport->count(), 'Outbox should have 1 message');
        $outboxEnvelope = $context->outboxTransport->getLastEnvelope();
        $this->assertNotNull($outboxEnvelope);
        $this->assertInstanceOf(TestMessage::class, $outboxEnvelope->getMessage());

        // And: AmqpTestMessage should be in AMQP transport only
        $this->assertEquals(1, $context->amqpTransport->count(), 'AMQP should have 1 message');
        $amqpEnvelope = $context->amqpTransport->getLastEnvelope();
        $this->assertNotNull($amqpEnvelope);
        $this->assertInstanceOf(AmqpTestMessage::class, $amqpEnvelope->getMessage());

        // Verify serialization format for AMQP message
        // AMQP transport uses standard serializer (FQN in type header, NOT semantic name)
        $amqpSerialized = $context->amqpTransport->getLastSerialized();
        $this->assertNotNull($amqpSerialized);
        $this->assertEquals(
            AmqpTestMessage::class,
            $amqpSerialized['headers']['type'],
            'AMQP message should have FQN (standard serializer, not MessageNameSerializer)'
        );

        $amqpBody = json_decode($amqpSerialized['body'], true);
        $this->assertArrayHasKey('eventId', $amqpBody);
        $this->assertArrayHasKey('payload', $amqpBody);
        $this->assertArrayHasKey('sentAt', $amqpBody);
        $this->assertEquals('AMQP Payload', $amqpBody['payload']);
    }
}
