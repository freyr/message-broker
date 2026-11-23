<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use Freyr\MessageBroker\Testing\InMemorySubscriptionProvider;
use Freyr\MessageBroker\Testing\InMemoryTransaction;
use Freyr\MessageBroker\Testing\OrderPlaced;
use Freyr\MessageBroker\Testing\Unserializable;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\Subscription;
use Freyr\MessageBroker\Webhook\WebhookProducer;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class WebhookProducerTest extends TestCase
{
    private InMemorySubscriptionProvider $subscriptions;
    private InMemoryDeliveryStore $store;

    protected function setUp(): void
    {
        $this->subscriptions = new InMemorySubscriptionProvider();
        $this->store = new InMemoryDeliveryStore();
    }

    public function testNSubscriptionsGiveNRowsOneForEachLane(): void
    {
        foreach (['crm', 'erp', 'bi'] as $id) {
            $this->subscriptions->add(new Subscription($id, "https://{$id}.example", ['whsec_x']), 'order.placed');
        }
        $this->subscriptions->add(new Subscription('other', 'https://other.example', ['whsec_x']), 'order.paid');
        $message = OrderPlaced::create('o-1', 1250);

        $this->producer()->produce($message, [
            'correlation_id' => 'c-1',
        ]);

        $records = $this->store->records();
        self::assertSame(['crm', 'erp', 'bi'], array_map(static fn (DeliveryRecord $r): string => $r->lane, $records));
        foreach ($records as $record) {
            self::assertSame($message->id, $record->messageId, 'each row has the message ID that the receiver gets');
            self::assertSame('order.placed', $record->messageName);
            self::assertSame('{"order_id":"o-1","total_cents":1250}', $record->body);
            self::assertSame([
                'correlation_id' => 'c-1',
            ], $record->headers);
            self::assertSame($message->createdAt, $record->createdAt);
            self::assertSame(0, $record->attempts);
            self::assertNull($record->availableAt, 'a new row is due at once');
            self::assertInstanceOf(UuidV7::class, Uuid::fromString($record->id));
        }
        self::assertCount(3, array_unique(array_map(static fn (DeliveryRecord $r): string => $r->id, $records)), 'each row has its own id');
    }

    public function testNoSubscriptionGivesNoRow(): void
    {
        $this->subscriptions->add(new Subscription('crm', 'https://crm.example', ['whsec_x']), 'order.paid');

        $this->producer()->produce(OrderPlaced::create('o-1', 1));

        self::assertSame([], $this->store->records());
    }

    public function testTheBodyIsTheSameBytesThatTheOutboxProducerStores(): void
    {
        $this->subscriptions->add(new Subscription('crm', 'https://crm.example', ['whsec_x']), 'order.placed');
        $outbox = new InMemoryOutboxStore();
        $message = OrderPlaced::create('zażółć "quoted" / slash', 99);

        $this->producer()->produce($message);
        new OutboxProducer($outbox, new JsonWireFormat())->produce($message);

        self::assertSame($outbox->row($message->id)->body, $this->store->records()[0]->body);
    }

    public function testThePayloadIsEncodedAlsoWithNoSubscription(): void
    {
        $this->expectException(JsonException::class);

        $this->producer()->produce(Unserializable::create());
    }

    public function testAPayloadThatCannotBeEncodedWritesNoRow(): void
    {
        $this->subscriptions->add(new Subscription('crm', 'https://crm.example', ['whsec_x']), 'broken.message');

        try {
            $this->producer()->produce(Unserializable::create());
            self::fail('expected JsonException');
        } catch (JsonException) {
        }

        self::assertSame([], $this->store->records());
    }

    public function testTheRowsRollBackWithTheTransactionOfTheApplication(): void
    {
        $transaction = new InMemoryTransaction();
        $this->store = new InMemoryDeliveryStore(transaction: $transaction);
        $this->subscriptions->add(new Subscription('crm', 'https://crm.example', ['whsec_x']), 'order.placed');

        $transaction->begin();
        $this->producer()->produce(OrderPlaced::create('o-1', 1));
        $transaction->rollBack();

        self::assertSame([], $this->store->records());
    }

    private function producer(): WebhookProducer
    {
        return new WebhookProducer($this->subscriptions, $this->store, new JsonWireFormat());
    }
}
