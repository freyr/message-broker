<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Webhook\DeliveryResult;
use Freyr\MessageBroker\Webhook\DeliveryResultKind;
use PHPUnit\Framework\TestCase;

final class DeliveryResultTest extends TestCase
{
    public function testADeliveredResultHasTheRequestTimeAndNoRetryTimeOrReasonCode(): void
    {
        $result = DeliveryResult::delivered(42, '200');

        self::assertSame(DeliveryResultKind::Delivered, $result->kind);
        self::assertSame(42, $result->requestMs);
        self::assertSame('200', $result->text);
        self::assertNull($result->retryAt);
        self::assertNull($result->reasonCode);
    }

    public function testADeliveredResultHasAnEmptyTextByDefault(): void
    {
        self::assertSame('', DeliveryResult::delivered(1)->text);
    }

    public function testAFailedResultHasAnOptionalRetryTimeOfTheReceiver(): void
    {
        $plain = DeliveryResult::failed(15, '500 oops');
        $withRetry = DeliveryResult::failed(16, '429', 1_749_722_460_000);

        self::assertSame(DeliveryResultKind::Failed, $plain->kind);
        self::assertSame(15, $plain->requestMs);
        self::assertSame('500 oops', $plain->text);
        self::assertNull($plain->retryAt);
        self::assertNull($plain->reasonCode);
        self::assertSame(1_749_722_460_000, $withRetry->retryAt);
    }

    public function testARefusedResultHasAReasonCode(): void
    {
        $result = DeliveryResult::refused('gone', 7, '410');

        self::assertSame(DeliveryResultKind::Refused, $result->kind);
        self::assertSame('gone', $result->reasonCode);
        self::assertSame(7, $result->requestMs);
        self::assertSame('410', $result->text);
        self::assertNull($result->retryAt);
    }

    public function testTheKindsHaveStableNames(): void
    {
        self::assertSame(
            ['delivered', 'failed', 'refused'],
            array_map(static fn (DeliveryResultKind $k): string => $k->value, DeliveryResultKind::cases()),
        );
    }
}
