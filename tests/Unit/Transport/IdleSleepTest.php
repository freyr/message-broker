<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport;

use Freyr\MessageBroker\Transport\IdleSleep;
use PHPUnit\Framework\TestCase;

final class IdleSleepTest extends TestCase
{
    public function testMicrosStaysWithinBasePlusJitter(): void
    {
        for ($i = 0; $i < 1_000; ++$i) {
            $micros = IdleSleep::micros(200, 50);
            self::assertGreaterThanOrEqual(200_000, $micros);
            self::assertLessThanOrEqual(250_000, $micros);
        }
    }

    public function testJitterCoversTheWholeRangeFromZeroToJitter(): void
    {
        $seen = [];
        for ($i = 0; $i < 30_000; ++$i) {
            $seen[IdleSleep::micros(0, 1)] = true;
        }

        self::assertGreaterThanOrEqual(0, min(array_keys($seen)));
        self::assertLessThanOrEqual(1_000, max(array_keys($seen)));
        self::assertArrayHasKey(0, $seen, 'no jitter is possible');
        self::assertArrayHasKey(1_000, $seen, 'the full jitter is possible');
        self::assertGreaterThan(900, count($seen), 'the sleep is randomized');
    }

    public function testZeroJitterReturnsBaseExactly(): void
    {
        self::assertSame(200_000, IdleSleep::micros(200, 0));
    }
}
