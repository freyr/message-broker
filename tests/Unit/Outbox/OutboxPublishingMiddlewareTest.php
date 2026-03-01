<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox;

use Freyr\Identity\Id;
use Freyr\MessageBroker\Contracts\MessageIdStamp;
use Freyr\MessageBroker\Contracts\MessageNameStamp;
use Freyr\MessageBroker\Contracts\OutboxPublisherInterface;
use Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware;
use Freyr\MessageBroker\Tests\Fixtures\TestOutboxEvent;
use Freyr\MessageBroker\Tests\Unit\MiddlewareStackFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Unit test for OutboxPublishingMiddleware.
 *
 * Tests that the middleware:
 * - Passes through non-OutboxMessage envelopes
 * - Passes through when no ReceivedStamp
 * - Passes through when publisher not registered for transport
 * - Delegates to publisher with clean envelope
 * - Throws when MessageNameStamp missing
 * - Throws when MessageIdStamp missing
 * - Short-circuits after publishing
 * - Builds clean envelope with only MessageIdStamp + MessageNameStamp
 */
#[CoversClass(OutboxPublishingMiddleware::class)]
final class OutboxPublishingMiddlewareTest extends TestCase
{
    private static function testMessageId(): Id
    {
        return Id::fromString('01ARYZ6S41TSV4RRFFQ69G5FAV');
    }

    public function testNonOutboxMessagePassesThrough(): void
    {
        $middleware = $this->createMiddleware([]);
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('outbox')]);

        $nextCalled = false;
        $middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'Non-OutboxMessage should pass through to next middleware');
    }

    public function testOutboxMessageWithoutReceivedStampPassesThrough(): void
    {
        $middleware = $this->createMiddleware([
            'outbox' => $this->createFakePublisher(),
        ]);
        $envelope = new Envelope(TestOutboxEvent::random());

        $nextCalled = false;
        $middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'Dispatch-phase envelope should pass through');
    }

    public function testPassesThroughWhenPublisherNotRegisteredForTransport(): void
    {
        $middleware = $this->createMiddleware([]);
        $envelope = new Envelope(TestOutboxEvent::random(), [new ReceivedStamp('outbox')]);

        $nextCalled = false;
        $middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertTrue($nextCalled, 'Should pass through when no publisher registered');
    }

    public function testThrowsWhenMessageNameStampMissing(): void
    {
        $middleware = $this->createMiddleware([
            'outbox' => $this->createFakePublisher(),
        ]);
        $envelope = new Envelope(TestOutboxEvent::random(), [
            new ReceivedStamp('outbox'),
            new MessageIdStamp(self::testMessageId()),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must contain MessageNameStamp/');

        $middleware->handle($envelope, MiddlewareStackFactory::createPassThrough());
    }

    public function testThrowsWhenMessageIdStampMissing(): void
    {
        $middleware = $this->createMiddleware([
            'outbox' => $this->createFakePublisher(),
        ]);
        $envelope = new Envelope(TestOutboxEvent::random(), [
            new ReceivedStamp('outbox'),
            new MessageNameStamp('test.event.sent'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must contain MessageIdStamp/');

        $middleware->handle($envelope, MiddlewareStackFactory::createPassThrough());
    }

    public function testDelegatesToPublisherWithCleanEnvelope(): void
    {
        $publishedEnvelope = null;
        $publisher = $this->createFakePublisher(function (Envelope $envelope) use (&$publishedEnvelope): void {
            $publishedEnvelope = $envelope;
        });

        $middleware = $this->createMiddleware([
            'outbox' => $publisher,
        ]);
        $messageId = self::testMessageId();
        $envelope = new Envelope(TestOutboxEvent::random(), [
            new ReceivedStamp('outbox'),
            new MessageIdStamp($messageId),
            new MessageNameStamp('test.event.sent'),
        ]);

        $middleware->handle($envelope, MiddlewareStackFactory::createPassThrough());

        $this->assertNotNull($publishedEnvelope, 'Publisher should have been called');

        $stamps = $publishedEnvelope->all();
        $this->assertArrayHasKey(MessageIdStamp::class, $stamps);
        $this->assertArrayHasKey(MessageNameStamp::class, $stamps);
        $this->assertArrayNotHasKey(ReceivedStamp::class, $stamps);

        $idStamp = $publishedEnvelope->last(MessageIdStamp::class);
        $this->assertNotNull($idStamp);
        $this->assertTrue($messageId->sameAs($idStamp->messageId));

        $nameStamp = $publishedEnvelope->last(MessageNameStamp::class);
        $this->assertNotNull($nameStamp);
        $this->assertSame('test.event.sent', $nameStamp->messageName);
    }

    public function testShortCircuitsAfterPublishing(): void
    {
        $middleware = $this->createMiddleware([
            'outbox' => $this->createFakePublisher(),
        ]);
        $envelope = new Envelope(TestOutboxEvent::random(), [
            new ReceivedStamp('outbox'),
            new MessageIdStamp(self::testMessageId()),
            new MessageNameStamp('test.event.sent'),
        ]);

        $nextCalled = false;
        $middleware->handle($envelope, MiddlewareStackFactory::createTracking($nextCalled));

        $this->assertFalse($nextCalled, 'Middleware should short-circuit after publishing');
    }

    public function testReturnsOriginalEnvelopeAfterPublishing(): void
    {
        $middleware = $this->createMiddleware([
            'outbox' => $this->createFakePublisher(),
        ]);
        $envelope = new Envelope(TestOutboxEvent::random(), [
            new ReceivedStamp('outbox'),
            new MessageIdStamp(self::testMessageId()),
            new MessageNameStamp('test.event.sent'),
        ]);

        $result = $middleware->handle($envelope, MiddlewareStackFactory::createPassThrough());

        $this->assertSame($envelope, $result, 'Should return original envelope after publishing');
    }

    /**
     * @param array<string, OutboxPublisherInterface> $publishers
     */
    private function createMiddleware(array $publishers): OutboxPublishingMiddleware
    {
        $locatorMap = [];
        foreach ($publishers as $name => $publisher) {
            $locatorMap[$name] = fn () => $publisher;
        }

        return new OutboxPublishingMiddleware(
            publisherLocator: new ServiceLocator($locatorMap),
            logger: new NullLogger(),
        );
    }

    private function createFakePublisher(?callable $callback = null): OutboxPublisherInterface
    {
        return new class($callback) implements OutboxPublisherInterface {
            /** @var callable|null */
            private $callback;

            public function __construct(?callable $callback = null)
            {
                $this->callback = $callback;
            }

            public function publish(Envelope $envelope): void
            {
                if ($this->callback !== null) {
                    ($this->callback)($envelope);
                }
            }
        };
    }
}
