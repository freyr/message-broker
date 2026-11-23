<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Outbox;

use Freyr\MessageBroker\Outbox\LaneLimits;
use Freyr\MessageBroker\Outbox\MessageTooLarge;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use Freyr\MessageBroker\Testing\InMemoryTransaction;
use Freyr\MessageBroker\Testing\OrderPlaced;
use PHPUnit\Framework\TestCase;

final class OutboxProducerTest extends TestCase
{
    // The body of OrderPlaced::create($orderId, 1) is {"order_id":"<id>","total_cents":1}: 31 bytes + the length of the ID.
    private const int BODY_OVERHEAD = 31;

    // The headers ['h' => $value] encode to {"h":"<value>"}: 8 bytes + the length of the value.
    private const int HEADER_OVERHEAD = 8;

    public function testABodyAtTheLimitPasses(): void
    {
        $store = new InMemoryOutboxStore();
        $message = OrderPlaced::create(str_repeat('a', 10), 1);

        self::producer($store, new LaneLimits(maxBodyBytes: self::BODY_OVERHEAD + 10))->produce($message);

        self::assertSame([$message->id], $store->unpublishedIds());
    }

    public function testABodyAboveTheLimitIsRefusedWithAllSixValuesAndNothingIsInserted(): void
    {
        $store = new InMemoryOutboxStore();
        $message = OrderPlaced::create(str_repeat('a', 11), 1);

        try {
            self::producer($store, new LaneLimits(maxBodyBytes: self::BODY_OVERHEAD + 10), 'orders')->produce($message);
            self::fail('expected MessageTooLarge');
        } catch (MessageTooLarge $e) {
            self::assertSame('orders', $e->lane);
            self::assertSame($message->id, $e->messageId);
            self::assertSame('order.placed', $e->messageName);
            self::assertSame('body', $e->part);
            self::assertSame(self::BODY_OVERHEAD + 11, $e->size);
            self::assertSame(self::BODY_OVERHEAD + 10, $e->limit);
            self::assertStringContainsString('orders', $e->getMessage());
            self::assertStringContainsString($message->id, $e->getMessage());
            self::assertStringContainsString('order.placed', $e->getMessage());
            self::assertStringContainsString('body', $e->getMessage());
            self::assertStringContainsString((string) (self::BODY_OVERHEAD + 11), $e->getMessage());
            self::assertStringContainsString((string) (self::BODY_OVERHEAD + 10), $e->getMessage());
            self::assertStringNotContainsString('aaaa', $e->getMessage(), 'the exception never holds the body');
        }

        self::assertSame([], $store->unpublishedIds());
    }

    public function testHeadersAtTheLimitPass(): void
    {
        $store = new InMemoryOutboxStore();
        $message = OrderPlaced::create('o-1', 1);

        self::producer($store, new LaneLimits(maxHeaderBytes: self::HEADER_OVERHEAD + 10))
            ->produce($message, [
                'h' => str_repeat('b', 10),
            ]);

        self::assertSame([$message->id], $store->unpublishedIds());
    }

    public function testHeadersAboveTheLimitAreRefusedAndNothingIsInserted(): void
    {
        $store = new InMemoryOutboxStore();
        $message = OrderPlaced::create('o-1', 1);

        try {
            self::producer($store, new LaneLimits(maxHeaderBytes: self::HEADER_OVERHEAD + 10), 'orders')
                ->produce($message, [
                    'h' => str_repeat('b', 11),
                ]);
            self::fail('expected MessageTooLarge');
        } catch (MessageTooLarge $e) {
            self::assertSame('orders', $e->lane);
            self::assertSame($message->id, $e->messageId);
            self::assertSame('order.placed', $e->messageName);
            self::assertSame('headers', $e->part);
            self::assertSame(self::HEADER_OVERHEAD + 11, $e->size);
            self::assertSame(self::HEADER_OVERHEAD + 10, $e->limit);
            self::assertStringNotContainsString('bbbb', $e->getMessage(), 'the exception never holds a header value');
        }

        self::assertSame([], $store->unpublishedIds());
    }

    public function testTheBodySizeIsTheEncodedSizeNotTheSizeOfThePhpString(): void
    {
        // Five times "é": 10 bytes in the PHP string, 30 bytes in the JSON form ("é" each).
        $message = OrderPlaced::create(str_repeat('é', 5), 1);
        $store = new InMemoryOutboxStore();

        try {
            // Above the size that the PHP string would give, below the encoded size.
            self::producer($store, new LaneLimits(maxBodyBytes: self::BODY_OVERHEAD + 10))->produce($message);
            self::fail('expected MessageTooLarge');
        } catch (MessageTooLarge $e) {
            self::assertSame('body', $e->part);
            self::assertSame(self::BODY_OVERHEAD + 30, $e->size);
        }

        self::assertSame([], $store->unpublishedIds());
    }

    public function testWithNoLimitsNothingIsRefused(): void
    {
        $store = new InMemoryOutboxStore();
        $message = OrderPlaced::create(str_repeat('a', 100_000), 1);

        self::producer($store, null)->produce($message, [
            'h' => str_repeat('b', 100_000),
        ]);
        self::producer($store, new LaneLimits())->produce(OrderPlaced::create('o-2', 1));

        self::assertCount(2, $store->unpublishedIds());
    }

    public function testARefusalInsideATransactionLeavesNoRow(): void
    {
        $transaction = new InMemoryTransaction();
        $store = new InMemoryOutboxStore($transaction);
        $producer = self::producer($store, new LaneLimits(maxBodyBytes: self::BODY_OVERHEAD + 3));

        $transaction->begin();
        $producer->produce(OrderPlaced::create('abc', 1));
        try {
            $producer->produce(OrderPlaced::create('abcd', 1));
            self::fail('expected MessageTooLarge');
        } catch (MessageTooLarge) {
            $transaction->rollBack();
        }

        self::assertSame([], $store->unpublishedIds());
    }

    private static function producer(InMemoryOutboxStore $store, ?LaneLimits $limits, string $lane = 'default'): OutboxProducer
    {
        return new OutboxProducer($store, new JsonWireFormat(), $lane, null, $limits);
    }
}
