<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Unit\Factory\EventBusFactory;
use Freyr\MessageBroker\Tests\Unit\Fixtures\SampleOutboxMessage;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for transport-specific serializer usage.
 *
 * Tests that:
 * - Outbox transport uses native serialiser (FQN in type header — internal storage)
 * - AMQP consumption transport uses InboxSerializer (FQN in type header from config mapping)
 * - Both stamp middlewares add stamps at dispatch time
 */
final class TransportSerializerTest extends TestCase
{
    public function testOutboxTransportUsesNativeSerializer(): void
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

        $message = new TestMessage(id: Id::new(), name: 'Outbox Test', timestamp: CarbonImmutable::now());

        // When: Message is dispatched
        $context->bus->dispatch($message);

        // Then: Message should be serialised with native serialiser
        $serialized = $context->outboxTransport->getLastSerialized();
        $this->assertNotNull($serialized);

        // Type header should contain FQN (native serialiser uses class name)
        $this->assertEquals(
            TestMessage::class,
            $serialized['headers']['type'],
            'Outbox transport should use native serialiser — type header should be FQN'
        );
    }

    public function testOutboxTransportPreservesStampsInHeaders(): void
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

        $message = new TestMessage(id: Id::new(), name: 'Outbox Test', timestamp: CarbonImmutable::now());

        // When: Message is dispatched
        $context->bus->dispatch($message);

        // Then: Stamps should be preserved in X-Message-Stamp-* headers
        $serialized = $context->outboxTransport->getLastSerialized();
        $this->assertNotNull($serialized);

        $headers = $serialized['headers'];
        $this->assertArrayHasKey(
            'X-Message-Stamp-Freyr\MessageBroker\Contracts\MessageIdStamp',
            $headers,
            'MessageIdStamp should be preserved in native format'
        );
        $this->assertArrayHasKey(
            'X-Message-Stamp-Freyr\MessageBroker\Contracts\MessageNameStamp',
            $headers,
            'MessageNameStamp should be preserved in native format'
        );
    }

    public function testAmqpConsumptionTransportUsesInboxSerializer(): void
    {
        // Given: EventBus with OutboxMessage routed directly to AMQP
        // MessageNameStampMiddleware adds MessageNameStamp at dispatch for all OutboxMessages
        // InboxSerializer::encode() reads the stamp and uses semantic name
        $context = EventBusFactory::createForOutboxTesting(
            messageTypes: [
                'test.sample.sent' => SampleOutboxMessage::class,
            ],
            routing: [
                SampleOutboxMessage::class => ['amqp'],
            ]
        );

        $message = new SampleOutboxMessage(
            eventId: Id::new(),
            payload: 'Direct AMQP',
            sentAt: CarbonImmutable::now(),
        );

        // When: Message is dispatched
        $context->bus->dispatch($message);

        // Then: InboxSerializer::encode() uses semantic name from MessageNameStamp
        $serialized = $context->amqpTransport->getLastSerialized();
        $this->assertNotNull($serialized);

        $this->assertEquals(
            'test.sample.sent',
            $serialized['headers']['type'],
            'InboxSerializer should use semantic name from MessageNameStamp'
        );
    }

    public function testOutboxUsesNativeSerializerAmqpUsesInboxSerializer(): void
    {
        // Given: EventBus with messages routed to different transports
        $context = EventBusFactory::createForOutboxTesting(
            messageTypes: [
                'test.message.sent' => TestMessage::class,
                'test.sample.sent' => SampleOutboxMessage::class,
            ],
            routing: [
                TestMessage::class => ['outbox'],
                SampleOutboxMessage::class => ['amqp'],
            ]
        );

        $outboxMessage = new TestMessage(
            id: Id::new(),
            name: 'Outbox Message',
            timestamp: CarbonImmutable::now(),
        );

        $amqpMessage = new SampleOutboxMessage(
            eventId: Id::new(),
            payload: 'AMQP Message',
            sentAt: CarbonImmutable::now(),
        );

        // When: Both messages are dispatched
        $context->bus->dispatch($outboxMessage);
        $context->bus->dispatch($amqpMessage);

        // Then: Outbox stores FQN (native serialiser — internal storage)
        $outboxSerialized = $context->outboxTransport->getLastSerialized();
        $this->assertNotNull($outboxSerialized);
        $this->assertEquals(
            TestMessage::class,
            $outboxSerialized['headers']['type'],
            'Outbox should have FQN in type header (native serialiser)'
        );

        // And: AMQP stores semantic name (InboxSerializer reads MessageNameStamp)
        $amqpSerialized = $context->amqpTransport->getLastSerialized();
        $this->assertNotNull($amqpSerialized);
        $this->assertEquals(
            'test.sample.sent',
            $amqpSerialized['headers']['type'],
            'AMQP should have semantic name (InboxSerializer reads MessageNameStamp)'
        );

        // Verify the transports use different formats
        $this->assertNotEquals(
            $outboxSerialized['headers']['type'],
            $amqpSerialized['headers']['type'],
            'Different transports should use different serialisation formats'
        );
    }
}
