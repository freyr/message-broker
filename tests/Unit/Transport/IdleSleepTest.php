<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport;

use Freyr\MessageBroker\Transport\IdleSleep;
use PHPUnit\Framework\TestCase;

final class IdleSleepTest extends TestCase
{
    public function testMicrosStaysWithinBasePlusJitter(): void
    {
        for ($i = 0; $i < 1_000; $i++) {
            $micros = IdleSleep::micros(200, 50);
            self::assertGreaterThanOrEqual(200_000, $micros);
            self::assertLessThanOrEqual(250_000, $micros);
        }
    }

    public function testZeroJitterReturnsBaseExactly(): void
    {
        self::assertSame(200_000, IdleSleep::micros(200, 0));
    }
}
