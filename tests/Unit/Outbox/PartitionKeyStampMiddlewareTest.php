<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox;

use Freyr\MessageBroker\Outbox\PartitionKeyStamp;
use Freyr\MessageBroker\Outbox\PartitionKeyStampMiddleware;
use Freyr\MessageBroker\Tests\Fixtures\TestOutboxEvent;
use Freyr\MessageBroker\Tests\Unit\MiddlewareStackFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Unit test for PartitionKeyStampMiddleware.
 *
 * Tests that the middleware:
 * - Throws LogicException if OutboxMessage lacks PartitionKeyStamp at dispatch
 * - Passes through OutboxMessage with PartitionKeyStamp
 * - Skips non-OutboxMessage envelopes
 * - Skips envelopes with ReceivedStamp (consume phase)
 */
#[CoversClass(PartitionKeyStampMiddleware::class)]
final class PartitionKeyStampMiddlewareTest extends TestCase
{
    private PartitionKeyStampMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new PartitionKeyStampMiddleware();
    }

    public function testOutboxMessageWithoutPartitionKeyStampThrows(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/PartitionKeyStamp/');

        $this->middleware->handle($envelope, MiddlewareStackFactory::createPassThrough());
    }

    public function testOutboxMessageWithPartitionKeyStampPassesThrough(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new PartitionKeyStamp('order-123')]);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'Middleware must call next in the stack');
        $stamp = $result->last(PartitionKeyStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame('order-123', $stamp->partitionKey);
    }

    public function testNonOutboxMessagePassesThroughWithoutValidation(): void
    {
        $envelope = new Envelope(new \stdClass());

        $nextCalled = false;
        $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'Non-OutboxMessage must pass through without validation');
    }

    public function testOutboxMessageWithReceivedStampSkipsValidation(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new ReceivedStamp('outbox')]);

        $nextCalled = false;
        $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'Consumed messages must skip validation');
    }

    public function testExceptionMessageIncludesClassName(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random());

        try {
            $this->middleware->handle($envelope, MiddlewareStackFactory::createPassThrough());
            $this->fail('Expected LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString(
                TestOutboxEvent::class,
                $e->getMessage(),
                'Exception message must include the event class name',
            );
        }
    }

    public function testOutboxMessageWithEmptyPartitionKeyPassesThrough(): void
    {
        $envelope = new Envelope(TestOutboxEvent::random(), [new PartitionKeyStamp('')]);

        $nextCalled = false;
        $result = $this->middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'Empty partition key is valid and must pass through');
        $stamp = $result->last(PartitionKeyStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame('', $stamp->partitionKey);
    }
}
