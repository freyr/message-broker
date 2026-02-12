<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge;
use Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Tests\Unit\Factory\MiddlewareStackFactory;
use Freyr\MessageBroker\Tests\Unit\Fixtures\CommerceTestMessage;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use Freyr\MessageBroker\Tests\Unit\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Unit test for OutboxToAmqpBridge middleware.
 *
 * Tests that the bridge:
 * - Publishes OutboxMessage with ReceivedStamp('outbox') + MessageIdStamp to AMQP
 * - Preserves the same MessageIdStamp (not generating a new one)
 * - Throws RuntimeException when MessageIdStamp is missing
 * - Passes through non-OutboxMessage envelopes
 * - Passes through OutboxMessage without ReceivedStamp (dispatch phase)
 * - Passes through OutboxMessage with wrong transport name
 */
final class OutboxToAmqpBridgeTest extends TestCase
{
    private InMemoryTransport $amqpSender;
    private OutboxToAmqpBridge $bridge;

    protected function setUp(): void
    {
        $this->amqpSender = new InMemoryTransport(new PhpSerializer());
        $this->bridge = new OutboxToAmqpBridge(
            senderLocator: new ServiceLocator([
                'amqp' => fn () => $this->amqpSender,
            ]),
            routingStrategy: new DefaultAmqpRoutingStrategy(),
            logger: new NullLogger(),
        );
    }

    public function testPublishesOutboxMessageWithCorrectStamps(): void
    {
        $messageId = '01234567-89ab-7def-8000-000000000001';
        $message = new TestMessage(id: Id::new(), name: 'Test Bridge', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [new ReceivedStamp('outbox'), new MessageIdStamp($messageId)]);

        $this->bridge->handle($envelope, MiddlewareStackFactory::createPassThrough());

        // Sender should have received the envelope
        $this->assertEquals(1, $this->amqpSender->count());

        $sentEnvelope = $this->amqpSender->getLastEnvelope();
        $this->assertNotNull($sentEnvelope);

        // Same MessageIdStamp preserved
        $stamp = $sentEnvelope->last(MessageIdStamp::class);
        $this->assertNotNull($stamp);
        $this->assertEquals($messageId, $stamp->messageId);

        // AmqpStamp with correct routing key
        $amqpStamp = $sentEnvelope->last(AmqpStamp::class);
        $this->assertNotNull($amqpStamp);
        $this->assertEquals('test.message.sent', $amqpStamp->getRoutingKey());
    }

    public function testThrowsWhenMessageIdStampMissing(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [new ReceivedStamp('outbox')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/without MessageIdStamp/');

        $this->bridge->handle($envelope, MiddlewareStackFactory::createPassThrough());
    }

    public function testNonOutboxMessagePassesThrough(): void
    {
        $message = new \stdClass();
        $envelope = new Envelope($message, [new ReceivedStamp('outbox')]);

        $nextCalled = false;
        $stack = MiddlewareStackFactory::createTracking($nextCalled);

        $this->bridge->handle($envelope, $stack);

        $this->assertTrue($nextCalled, 'Non-OutboxMessage should pass through to next middleware');
        $this->assertEquals(0, $this->amqpSender->count(), 'Sender should not be called');
    }

    public function testOutboxMessageWithoutReceivedStampPassesThrough(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message);

        $nextCalled = false;
        $stack = MiddlewareStackFactory::createTracking($nextCalled);

        $this->bridge->handle($envelope, $stack);

        $this->assertTrue($nextCalled, 'Dispatch-phase envelope should pass through');
        $this->assertEquals(0, $this->amqpSender->count());
    }

    public function testOutboxMessageFromWrongTransportPassesThrough(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [new ReceivedStamp('amqp_orders')]);

        $nextCalled = false;
        $stack = MiddlewareStackFactory::createTracking($nextCalled);

        $this->bridge->handle($envelope, $stack);

        $this->assertTrue($nextCalled, 'Wrong transport should pass through');
        $this->assertEquals(0, $this->amqpSender->count());
    }

    public function testShortCircuitsAfterPublishing(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [
            new ReceivedStamp('outbox'),
            new MessageIdStamp('01234567-89ab-7def-8000-000000000001'),
        ]);

        $nextCalled = false;
        $stack = MiddlewareStackFactory::createTracking($nextCalled);

        $this->bridge->handle($envelope, $stack);

        $this->assertFalse($nextCalled, 'Bridge should short-circuit after publishing');
        $this->assertEquals(1, $this->amqpSender->count());
    }

    public function testRoutesToCustomExchangeViaSenderLocator(): void
    {
        $commerceSender = new InMemoryTransport(new PhpSerializer());

        $bridge = new OutboxToAmqpBridge(
            senderLocator: new ServiceLocator([
                'amqp' => fn () => $this->amqpSender,
                'commerce' => fn () => $commerceSender,
            ]),
            routingStrategy: new DefaultAmqpRoutingStrategy(),
            logger: new NullLogger(),
        );

        $message = new CommerceTestMessage(orderId: Id::new(), amount: 99.99, placedAt: CarbonImmutable::now());
        $envelope = new Envelope($message, [
            new ReceivedStamp('outbox'),
            new MessageIdStamp('01234567-89ab-7def-8000-000000000001'),
        ]);

        $bridge->handle($envelope, MiddlewareStackFactory::createPassThrough());

        // Commerce sender should receive the envelope, not the default AMQP sender
        $this->assertEquals(0, $this->amqpSender->count(), 'Default AMQP sender should not receive the message');
        $this->assertEquals(1, $commerceSender->count(), 'Commerce sender should receive the message');

        // Verify routing key uses message name convention
        $sentEnvelope = $commerceSender->getLastEnvelope();
        $this->assertNotNull($sentEnvelope);
        $amqpStamp = $sentEnvelope->last(AmqpStamp::class);
        $this->assertNotNull($amqpStamp);
        $this->assertEquals('commerce.order.placed', $amqpStamp->getRoutingKey());
    }

    public function testThrowsWhenSenderNotInLocator(): void
    {
        // Bridge only has 'amqp' sender — CommerceTestMessage requires 'commerce'
        $message = new CommerceTestMessage(orderId: Id::new(), amount: 50.00, placedAt: CarbonImmutable::now());
        $envelope = new Envelope($message, [
            new ReceivedStamp('outbox'),
            new MessageIdStamp('01234567-89ab-7def-8000-000000000001'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No sender "commerce" configured/');

        $this->bridge->handle($envelope, MiddlewareStackFactory::createPassThrough());
    }
}
