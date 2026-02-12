<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\MessageIdStampMiddleware;
use Freyr\MessageBroker\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Tests\Unit\Fixtures\TestMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
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

        $result = $this->middleware->handle($envelope, $this->createPassThroughStack());

        $stamp = $result->last(MessageIdStamp::class);
        $this->assertNotNull($stamp, 'OutboxMessage should receive MessageIdStamp');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $stamp->messageId,
            'MessageId should be a valid UUID v7'
        );
    }

    public function testNonOutboxMessagePassesThroughWithoutStamp(): void
    {
        $message = new \stdClass();
        $envelope = new Envelope($message);

        $result = $this->middleware->handle($envelope, $this->createPassThroughStack());

        $this->assertNull(
            $result->last(MessageIdStamp::class),
            'Non-OutboxMessage should not receive MessageIdStamp'
        );
    }

    public function testOutboxMessageWithExistingStampIsNotReStamped(): void
    {
        $existingStamp = new MessageIdStamp('01234567-89ab-7def-8000-000000000001');
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [$existingStamp]);

        $result = $this->middleware->handle($envelope, $this->createPassThroughStack());

        $stamp = $result->last(MessageIdStamp::class);
        $this->assertNotNull($stamp);
        $this->assertEquals(
            '01234567-89ab-7def-8000-000000000001',
            $stamp->messageId,
            'Existing MessageIdStamp should not be overwritten'
        );
    }

    public function testOutboxMessageWithReceivedStampIsNotStamped(): void
    {
        $message = new TestMessage(id: Id::new(), name: 'Test', timestamp: CarbonImmutable::now());
        $envelope = new Envelope($message, [new ReceivedStamp('outbox')]);

        $result = $this->middleware->handle($envelope, $this->createPassThroughStack());

        // ReceivedStamp means redelivery — stamp should already exist from original dispatch.
        // Middleware must not add a new one.
        $stamps = $result->all(MessageIdStamp::class);
        $this->assertEmpty($stamps, 'Redelivered message should not get a new MessageIdStamp');
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
}
