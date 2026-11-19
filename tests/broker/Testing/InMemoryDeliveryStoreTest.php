<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Testing\InMemoryDeliveryStore;
use Freyr\MessageBroker\Testing\InMemorySubscriptionProvider;
use Freyr\MessageBroker\Testing\InMemoryTransaction;
use Freyr\MessageBroker\Webhook\DeliveryCompletion;
use Freyr\MessageBroker\Webhook\DeliveryCompletionKind;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\Subscription;
use PHPUnit\Framework\TestCase;

/** The helpers of the in-memory webhook fakes that the contract test case does not cover. */
final class InMemoryDeliveryStoreTest extends TestCase
{
    public function testAnInsertRollsBackWithTheTransaction(): void
    {
        $transaction = new InMemoryTransaction();
        $store = new InMemoryDeliveryStore(transaction: $transaction);
        $kept = self::record('01900000-0000-7000-8000-000000000001');
        $store->insert([$kept]);

        $transaction->begin();
        $store->insert([self::record('01900000-0000-7000-8000-000000000002')]);
        $transaction->rollBack();

        self::assertSame([$kept], $store->records());
    }

    public function testTheOutcomeHelperShowsTheFinalStateOfARow(): void
    {
        $deadLetters = new InMemoryDeadLetterStore();
        $store = new InMemoryDeliveryStore($deadLetters);
        $store->insert([self::record('01900000-0000-7000-8000-000000000001'), self::record('01900000-0000-7000-8000-000000000002')]);
        [$delivered, $dead] = $store->claim(['crm'], 10, 10, 1_000);

        self::assertNull($store->outcome($delivered->delivery->id), 'unfinished');
        $store->complete([
            DeliveryCompletion::delivered($delivered),
            DeliveryCompletion::dead($dead, new DeadLetter('01900000-0000-7000-8000-0000000000dd', 'webhook:crm', 'm-2', 'order.placed', '{}', [], 'failed', 'x', '', 1, 1)),
        ]);

        self::assertSame(DeliveryCompletionKind::Delivered, $store->outcome($delivered->delivery->id));
        self::assertSame(DeliveryCompletionKind::Dead, $store->outcome($dead->delivery->id));
        self::assertSame(1, $store->row($delivered->delivery->id)->attempts);
        self::assertSame($deadLetters, $store->deadLetters());
        self::assertSame(1, $deadLetters->count(new DeadLetterFilter()));
        self::assertSame(1, $store->claimCalls);
    }

    public function testTheSubscriptionProviderGivesTheSubscriptionsOfAMessageByLaneAndAllLanes(): void
    {
        $crm = new Subscription('crm', 'https://crm.example', ['whsec_a']);
        $erp = new Subscription('erp', 'https://erp.example', ['whsec_b']);
        $provider = new InMemorySubscriptionProvider();
        $provider->add($crm, 'order.placed', 'order.paid');
        $provider->add($erp, 'order.placed');

        self::assertSame([$crm, $erp], $provider->forMessage('order.placed'));
        self::assertSame([$crm], $provider->forMessage('order.paid'));
        self::assertSame([], $provider->forMessage('order.shipped'));
        self::assertSame($erp, $provider->find('erp'));
        self::assertSame(['crm', 'erp'], $provider->lanes());

        $provider->remove('crm');

        self::assertNull($provider->find('crm'));
        self::assertSame(['erp'], $provider->lanes());
        self::assertSame(2, $provider->lanesCalls);
    }

    private static function record(string $id): DeliveryRecord
    {
        return new DeliveryRecord($id, 'm-'.substr($id, -1), 'crm', 'order.placed', '{}', [], 1_000);
    }
}
