<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Webhook\ClaimedDelivery;
use Freyr\MessageBroker\Webhook\DeliveryPair;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\Subscription;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliveryRecordTest extends TestCase
{
    public function testANewRecordHasNoAttemptAndIsDueAtOnce(): void
    {
        $record = self::record();

        self::assertSame(0, $record->attempts);
        self::assertNull($record->availableAt);
    }

    public function testAcceptsValuesAtTheColumnLimits(): void
    {
        $record = self::record(lane: str_repeat('l', 64), name: str_repeat('ń', 255));

        self::assertSame(64, mb_strlen($record->lane));
        self::assertSame(255, mb_strlen($record->messageName), 'limits count characters, not bytes');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty lane' => ['', 'order.placed', 'Delivery lane must be 1 to 64 characters, got 0: ""'];
        yield 'over-long lane' => [str_repeat('l', 65), 'order.placed', 'Delivery lane must be 1 to 64 characters, got 65'];
        yield 'empty name' => ['crm', '', 'Delivery message name must be 1 to 255 characters, got 0'];
        yield 'over-long name' => ['crm', str_repeat('n', 256), 'Delivery message name must be 1 to 255 characters, got 256'];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsValuesOutsideTheColumnLimits(string $lane, string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::record(lane: $lane, name: $name);
    }

    public function testAClaimedDeliveryKeepsTheRecordAndTheLease(): void
    {
        $record = self::record();
        $claimed = new ClaimedDelivery($record, 1_749_722_460_123);
        $subscription = new Subscription('crm', 'https://crm.example', ['whsec_a']);
        $pair = new DeliveryPair($claimed, $subscription);

        self::assertSame($record, $claimed->delivery);
        self::assertSame(1_749_722_460_123, $claimed->lease);
        self::assertSame($claimed, $pair->claimed);
        self::assertSame($subscription, $pair->subscription);
    }

    private static function record(string $lane = 'crm', string $name = 'order.placed'): DeliveryRecord
    {
        return new DeliveryRecord(
            id: '01900000-0000-7000-8000-000000000001',
            messageId: '01900000-0000-7000-8000-0000000000aa',
            lane: $lane,
            messageName: $name,
            body: '{}',
            headers: [],
            createdAt: 1_749_722_400_123,
        );
    }
}
