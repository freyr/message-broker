<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Retry;

use Freyr\MessageBroker\Retry\Backoff;
use PHPUnit\Framework\TestCase;

final class BackoffTest extends TestCase
{
    public function testExponentialGrowthFromInitialDelay(): void
    {
        $backoff = Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000, multiplier: 4.0);

        self::assertSame(1_000, $backoff->delayForAttempt(1));
        self::assertSame(4_000, $backoff->delayForAttempt(2));
        self::assertSame(16_000, $backoff->delayForAttempt(3));
        self::assertSame(64_000, $backoff->delayForAttempt(4));
    }

    public function testDelayIsCappedAtMax(): void
    {
        $backoff = Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 5_000);

        self::assertSame(5_000, $backoff->delayForAttempt(10));
    }

    public function testAttemptBelowOneIsTreatedAsFirstAttempt(): void
    {
        $backoff = Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 5_000);

        self::assertSame(1_000, $backoff->delayForAttempt(0));
    }
}
