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
 * Unit test for transport-specific serializer usage.
 *
 * Tests that:
 * - Outbox transport uses MessageNameSerializer (semantic names in type header)
 * - AMQP transport uses standard Symfony serializer (FQN in type header)
 * - This ensures MessageNameSerializer is only triggered for outbox messages
 */
final class TransportSerializerTest extends TestCase
{
    public function testOutboxTransportUsesMessageNameSerializer(): void
    {
        // Given: EventBus with message routed to outbox
        $context = EventBusFactory::createForOutboxTesting(
            messageTypes: [
                'test.message.sent' => TestMessage::class,
            ],
            routing: [
                TestMessage::class => ['outbox'],
            ]
        );

        $message = new TestMessage(
            id: Id::new(),
            name: 'Outbox Test',
            timestamp: CarbonImmutable::now(),
        );

        // When: Message is dispatched
        $context->bus->dispatch($message);

        // Then: Message should be serialized with MessageNameSerializer
        $serialized = $context->outboxTransport->getLastSerialized();
        $this->assertNotNull($serialized);

        // Type header should contain semantic name (NOT FQN)
        $this->assertEquals(
            'test.message.sent',
            $serialized['headers']['type'],
            'Outbox transport should use MessageNameSerializer - type header should be semantic name'
        );

        $this->assertNotEquals(
            TestMessage::class,
            $serialized['headers']['type'],
            'Outbox transport should NOT have FQN in type header'
        );
    }

    public function testAmqpTransportUsesStandardSerializer(): void
    {
        // Given: EventBus with message routed directly to AMQP
        $context = EventBusFactory::createForOutboxTesting(
            messageTypes: [
                'test.amqp.sent' => AmqpTestMessage::class,
            ],
            routing: [
                AmqpTestMessage::class => ['amqp'],
            ]
        );

        $message = new AmqpTestMessage(
            eventId: Id::new(),
            payload: 'Direct AMQP',
            sentAt: CarbonImmutable::now(),
        );

        // When: Message is dispatched
        $context->bus->dispatch($message);

        // Then: Message should be serialized with standard Symfony serializer
        $serialized = $context->amqpTransport->getLastSerialized();
        $this->assertNotNull($serialized);

        // Type header should contain FQN (NOT semantic name)
        $this->assertEquals(
            AmqpTestMessage::class,
            $serialized['headers']['type'],
            'AMQP transport should use standard serializer - type header should be FQN'
        );

        $this->assertNotEquals(
            'test.amqp.sent',
            $serialized['headers']['type'],
            'AMQP transport should NOT have semantic name in type header'
        );
    }

    public function testDifferentTransportsUseDifferentSerializers(): void
    {
        // Given: EventBus with messages routed to different transports
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
            payload: 'AMQP Message',
            sentAt: CarbonImmutable::now(),
        );

        // When: Both messages are dispatched
        $context->bus->dispatch($outboxMessage);
        $context->bus->dispatch($amqpMessage);

        // Then: Outbox message should have semantic name
        $outboxSerialized = $context->outboxTransport->getLastSerialized();
        $this->assertNotNull($outboxSerialized);
        $this->assertEquals(
            'test.message.sent',
            $outboxSerialized['headers']['type'],
            'Outbox message should have semantic name'
        );

        // And: AMQP message should have FQN
        $amqpSerialized = $context->amqpTransport->getLastSerialized();
        $this->assertNotNull($amqpSerialized);
        $this->assertEquals(
            AmqpTestMessage::class,
            $amqpSerialized['headers']['type'],
            'AMQP message should have FQN'
        );

        // Verify they are different
        $this->assertNotEquals(
            $outboxSerialized['headers']['type'],
            $amqpSerialized['headers']['type'],
            'Different transports should use different serialization formats'
        );
    }

    public function testMessageNameSerializerOnlyTriggeredForOutbox(): void
    {
        // Given: Multiple messages with MessageName attributes
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

        // Both messages have #[MessageName] attribute
        // But only TestMessage (routed to outbox) should use semantic name
        // AmqpTestMessage (routed to AMQP) should ignore the attribute

        $outboxMessage = new TestMessage(
            id: Id::new(),
            name: 'Test',
            timestamp: CarbonImmutable::now(),
        );

        $amqpMessage = new AmqpTestMessage(
            eventId: Id::new(),
            payload: 'Test',
            sentAt: CarbonImmutable::now(),
        );

        // When: Messages are dispatched
        $context->bus->dispatch($outboxMessage);
        $context->bus->dispatch($amqpMessage);

        // Then: Outbox respects #[MessageName] attribute
        $outboxSerialized = $context->outboxTransport->getLastSerialized();
        $this->assertEquals('test.message.sent', $outboxSerialized['headers']['type']);

        // And: AMQP ignores #[MessageName] attribute (uses FQN)
        $amqpSerialized = $context->amqpTransport->getLastSerialized();
        $this->assertEquals(AmqpTestMessage::class, $amqpSerialized['headers']['type']);
    }
}
