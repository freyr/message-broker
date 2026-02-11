<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\EventBridge\OutboxToAmqpBridge;
use Freyr\MessageBroker\Outbox\Routing\DefaultAmqpRoutingStrategy;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use Freyr\MessageBroker\Tests\Unit\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
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
            amqpSender: $this->amqpSender,
            routingStrategy: new DefaultAmqpRoutingStrategy(),
            logger: new NullLogger(),
        );
    }

    public function testPublishesOutboxMessageWithCorrectStamps(): void
    {
        $messageId = '01234567-89ab-7def-8000-000000000001';
        $message = new TestMessage(
            id: Id::new(),
            name: 'Test Bridge',
            timestamp: CarbonImmutable::now(),
        );
        $envelope = new Envelope($message, [
            new ReceivedStamp('outbox'),
            new MessageIdStamp($messageId),
        ]);

        $this->bridge->handle($envelope, $this->createPassThroughStack());

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
        $message = new TestMessage(
            id: Id::new(),
            name: 'Test',
            timestamp: CarbonImmutable::now(),
        );
        $envelope = new Envelope($message, [
            new ReceivedStamp('outbox'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/without MessageIdStamp/');

        $this->bridge->handle($envelope, $this->createPassThroughStack());
    }

    public function testNonOutboxMessagePassesThrough(): void
    {
        $message = new \stdClass();
        $envelope = new Envelope($message, [
            new ReceivedStamp('outbox'),
        ]);

        $nextCalled = false;
        $stack = $this->createTrackingStack($nextCalled);

        $this->bridge->handle($envelope, $stack);

        $this->assertTrue($nextCalled, 'Non-OutboxMessage should pass through to next middleware');
        $this->assertEquals(0, $this->amqpSender->count(), 'Sender should not be called');
    }

    public function testOutboxMessageWithoutReceivedStampPassesThrough(): void
    {
        $message = new TestMessage(
            id: Id::new(),
            name: 'Test',
            timestamp: CarbonImmutable::now(),
        );
        $envelope = new Envelope($message);

        $nextCalled = false;
        $stack = $this->createTrackingStack($nextCalled);

        $this->bridge->handle($envelope, $stack);

        $this->assertTrue($nextCalled, 'Dispatch-phase envelope should pass through');
        $this->assertEquals(0, $this->amqpSender->count());
    }

    public function testOutboxMessageFromWrongTransportPassesThrough(): void
    {
        $message = new TestMessage(
            id: Id::new(),
            name: 'Test',
            timestamp: CarbonImmutable::now(),
        );
        $envelope = new Envelope($message, [
            new ReceivedStamp('amqp_orders'),
        ]);

        $nextCalled = false;
        $stack = $this->createTrackingStack($nextCalled);

        $this->bridge->handle($envelope, $stack);

        $this->assertTrue($nextCalled, 'Wrong transport should pass through');
        $this->assertEquals(0, $this->amqpSender->count());
    }

    public function testShortCircuitsAfterPublishing(): void
    {
        $message = new TestMessage(
            id: Id::new(),
            name: 'Test',
            timestamp: CarbonImmutable::now(),
        );
        $envelope = new Envelope($message, [
            new ReceivedStamp('outbox'),
            new MessageIdStamp('01234567-89ab-7def-8000-000000000001'),
        ]);

        $nextCalled = false;
        $stack = $this->createTrackingStack($nextCalled);

        $this->bridge->handle($envelope, $stack);

        $this->assertFalse($nextCalled, 'Bridge should short-circuit after publishing');
        $this->assertEquals(1, $this->amqpSender->count());
    }

    private function createPassThroughStack(): StackInterface
    {
        $noOp = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope;
            }
        };

        return new StackMiddleware($noOp);
    }

    private function createTrackingStack(bool &$nextCalled): StackInterface
    {
        $tracking = new class ($nextCalled) implements MiddlewareInterface {
            public function __construct(private bool &$called) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->called = true;
                return $envelope;
            }
        };

        return new StackMiddleware($tracking);
    }
}
