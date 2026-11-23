<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\Webhook\ClaimedDelivery;
use Freyr\MessageBroker\Webhook\DeliveryCompletion;
use Freyr\MessageBroker\Webhook\DeliveryCompletionKind;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use PHPUnit\Framework\TestCase;

final class DeliveryCompletionTest extends TestCase
{
    public function testDeliveredCountsTheAttempt(): void
    {
        $claimed = self::claimed(attempts: 2);

        $completion = DeliveryCompletion::delivered($claimed);

        self::assertSame($claimed, $completion->claimed);
        self::assertSame(DeliveryCompletionKind::Delivered, $completion->kind);
        self::assertSame(3, $completion->attempts);
        self::assertNull($completion->availableAt);
        self::assertNull($completion->deadLetter);
    }

    public function testRetryCountsTheAttemptAndSetsTheNextTime(): void
    {
        $completion = DeliveryCompletion::retry(self::claimed(attempts: 0), 1_749_722_405_000);

        self::assertSame(DeliveryCompletionKind::Retry, $completion->kind);
        self::assertSame(1, $completion->attempts);
        self::assertSame(1_749_722_405_000, $completion->availableAt);
        self::assertNull($completion->deadLetter);
    }

    public function testDeadTakesTheAttemptCountOfTheDeadLetter(): void
    {
        $deadLetter = new DeadLetter('01900000-0000-7000-8000-0000000000dd', 'webhook:crm', 'm', 'order.placed', '{}', [], 'failed', 'x', '', 8, 1);

        $completion = DeliveryCompletion::dead(self::claimed(attempts: 7), $deadLetter);

        self::assertSame(DeliveryCompletionKind::Dead, $completion->kind);
        self::assertSame(8, $completion->attempts);
        self::assertNull($completion->availableAt);
        self::assertSame($deadLetter, $completion->deadLetter);
    }

    private static function claimed(int $attempts): ClaimedDelivery
    {
        return new ClaimedDelivery(new DeliveryRecord(
            id: '01900000-0000-7000-8000-000000000001',
            messageId: '01900000-0000-7000-8000-0000000000aa',
            lane: 'crm',
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: 1_749_722_400_123,
            attempts: $attempts,
            availableAt: 1_749_722_460_123,
        ), 1_749_722_460_123);
    }
}
