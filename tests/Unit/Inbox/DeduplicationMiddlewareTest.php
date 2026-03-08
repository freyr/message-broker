<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Inbox;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Contracts\DeduplicationStore;
use Freyr\MessageBroker\Contracts\MessageIdStamp;
use Freyr\MessageBroker\Inbox\DeduplicationMiddleware;
use Freyr\MessageBroker\Tests\Fixtures\TestInboxEvent;
use Freyr\MessageBroker\Tests\Unit\MiddlewareStackFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Unit test for DeduplicationMiddleware.
 *
 * Tests that the middleware:
 * - Passes through messages without ReceivedStamp (dispatch path)
 * - Passes through received messages without MessageIdStamp
 * - Calls next middleware for new messages (store returns false)
 * - Short-circuits for duplicate messages (store returns true)
 * - Passes message FQN as messageName argument to the store
 */
#[CoversClass(DeduplicationMiddleware::class)]
final class DeduplicationMiddlewareTest extends TestCase
{
    #[Test]
    public function itPassesThroughMessageWithoutReceivedStamp(): void
    {
        $store = $this->createMock(DeduplicationStore::class);
        $store->expects($this->never())
            ->method('isDuplicate');

        $middleware = new DeduplicationMiddleware($store);
        $envelope = new Envelope(TestInboxEvent::random());

        $nextCalled = false;
        $middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'Dispatch-path message should pass through');
    }

    #[Test]
    public function itPassesThroughReceivedMessageWithoutMessageIdStamp(): void
    {
        $store = $this->createMock(DeduplicationStore::class);
        $store->expects($this->never())
            ->method('isDuplicate');

        $middleware = new DeduplicationMiddleware($store);
        $envelope = new Envelope(TestInboxEvent::random(), [new ReceivedStamp('amqp')]);

        $nextCalled = false;
        $middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'Message without MessageIdStamp should pass through');
    }

    #[Test]
    public function itCallsNextMiddlewareForNewMessage(): void
    {
        $store = $this->createStub(DeduplicationStore::class);
        $store->method('isDuplicate')
            ->willReturn(false);

        $middleware = new DeduplicationMiddleware($store);
        $envelope = new Envelope(TestInboxEvent::random(), [
            new ReceivedStamp('amqp'),
            new MessageIdStamp(Id::new()),
        ]);

        $nextCalled = false;
        $middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'New message should be forwarded to next middleware');
    }

    #[Test]
    public function itShortCircuitsDuplicateMessage(): void
    {
        $store = $this->createStub(DeduplicationStore::class);
        $store->method('isDuplicate')
            ->willReturn(true);

        $middleware = new DeduplicationMiddleware($store);
        $envelope = new Envelope(TestInboxEvent::random(), [
            new ReceivedStamp('amqp'),
            new MessageIdStamp(Id::new()),
        ]);

        $nextCalled = false;
        $result = $middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertFalse($nextCalled, 'Duplicate message should short-circuit');
        $this->assertSame($envelope, $result, 'Should return original envelope');
    }

    #[Test]
    public function itPassesMessageFqnAsMessageNameToStore(): void
    {
        $messageId = Id::new();

        $store = $this->createMock(DeduplicationStore::class);
        $store->expects($this->once())
            ->method('isDuplicate')
            ->with($messageId, TestInboxEvent::class)
            ->willReturn(false);

        $middleware = new DeduplicationMiddleware($store);
        $envelope = new Envelope(TestInboxEvent::random(), [
            new ReceivedStamp('amqp'),
            new MessageIdStamp($messageId),
        ]);

        $nextCalled = false;
        $middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'New message should be forwarded to next middleware');
    }
}
