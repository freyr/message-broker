<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\MessageNameStampMiddleware;
use Freyr\MessageBroker\Stamp\MessageNameStamp;
use Freyr\MessageBroker\Tests\Unit\Factory\MiddlewareStackFactory;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Unit test for MessageNameStampMiddleware.
 *
 * Tests that the middleware:
 * - Stamps OutboxMessage envelopes with MessageNameStamp at dispatch time
 * - Skips non-OutboxMessage envelopes
 * - Skips envelopes with ReceivedStamp (redelivery)
 * - Does not overwrite existing MessageNameStamp (idempotent)
 * - Throws when #[MessageName] attribute is missing
 */
final class MessageNameStampMiddlewareTest extends TestCase
{
    private MessageNameStampMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new MessageNameStampMiddleware();
    }

    public function testOutboxMessageGetsStampedWithMessageNameStamp(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $stamp = $result->last(MessageNameStamp::class);
        $this->assertNotNull($stamp, 'OutboxMessage should receive MessageNameStamp');
        $this->assertSame('test.message.sent', $stamp->messageName);
        $this->assertTrue($nextCalled, 'Middleware must always call next in the stack');
    }

    public function testNonOutboxMessagePassesThroughWithoutStamp(): void
    {
        $message = new \stdClass();
        $envelope = new Envelope($message);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertNull(
            $result->last(MessageNameStamp::class),
            'Non-OutboxMessage should not receive MessageNameStamp'
        );
        $this->assertTrue($nextCalled, 'Middleware must always call next in the stack');
    }

    public function testOutboxMessageWithExistingStampIsNotReStamped(): void
    {
        $existingStamp = new MessageNameStamp('custom.name.override');
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [$existingStamp]);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $stamp = $result->last(MessageNameStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame(
            'custom.name.override',
            $stamp->messageName,
            'Existing MessageNameStamp should not be overwritten'
        );
        $this->assertTrue($nextCalled, 'Middleware must always call next in the stack');
    }

    public function testOutboxMessageWithReceivedStampIsNotStamped(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [new ReceivedStamp('outbox')]);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $stamps = $result->all(MessageNameStamp::class);
        $this->assertEmpty($stamps, 'Redelivered message should not get a new MessageNameStamp');
        $this->assertTrue($nextCalled, 'Middleware must always call next in the stack');
    }

    public function testThrowsWhenMessageNameAttributeMissing(): void
    {
        $message = new class implements \Freyr\MessageBroker\Outbox\OutboxMessage {};
        $envelope = new Envelope($message);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must have #\[MessageName\] attribute/');

        $this->middleware->handle($envelope, MiddlewareStackFactory::createPassThrough());
    }
}
