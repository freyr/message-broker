<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Contracts\MessageIdStamp;
use Freyr\MessageBroker\Outbox\MessageIdStampMiddleware;
use Freyr\MessageBroker\Tests\Unit\Factory\MiddlewareStackFactory;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Unit test for MessageIdStampMiddleware.
 *
 * Tests that the middleware:
 * - Stamps OutboxMessage envelopes with MessageIdStamp at dispatch time
 * - Skips non-OutboxMessage envelopes
 * - Skips envelopes with ReceivedStamp (redelivery)
 * - Does not overwrite existing MessageIdStamp (idempotent)
 */
final class MessageIdStampMiddlewareTest extends TestCase
{
    private MessageIdStampMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new MessageIdStampMiddleware();
    }

    public function testOutboxMessageGetsStampedWithMessageIdStamp(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $stamp = $result->last(MessageIdStamp::class);
        $this->assertNotNull($stamp, 'OutboxMessage should receive MessageIdStamp');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $stamp->messageId,
            'MessageId should be a valid UUID v7'
        );
        $this->assertTrue($nextCalled, 'Middleware must always call next in the stack');
    }

    public function testNonOutboxMessagePassesThroughWithoutStamp(): void
    {
        $message = new \stdClass();
        $envelope = new Envelope($message);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertNull(
            $result->last(MessageIdStamp::class),
            'Non-OutboxMessage should not receive MessageIdStamp'
        );
        $this->assertTrue($nextCalled, 'Middleware must always call next in the stack');
    }

    public function testOutboxMessageWithExistingStampIsNotReStamped(): void
    {
        $existingStamp = new MessageIdStamp(Id::fromString('01234567-89ab-7def-8000-000000000001'));
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [$existingStamp]);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $stamp = $result->last(MessageIdStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame(
            '01234567-89ab-7def-8000-000000000001',
            (string) $stamp->messageId,
            'Existing MessageIdStamp should not be overwritten'
        );
        $this->assertTrue($nextCalled, 'Middleware must always call next in the stack');
    }

    public function testOutboxMessageWithReceivedStampIsNotStamped(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [new ReceivedStamp('outbox')]);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        // ReceivedStamp means redelivery — stamp should already exist from original dispatch.
        // Middleware must not add a new one.
        $stamps = $result->all(MessageIdStamp::class);
        $this->assertEmpty($stamps, 'Redelivered message should not get a new MessageIdStamp');
        $this->assertTrue($nextCalled, 'Middleware must always call next in the stack');
    }
}
