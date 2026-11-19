<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliveryLoopConfigTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $config = new DeliveryLoopConfig();

        self::assertSame(60_000, $config->leaseMs);
        self::assertSame(100, $config->batchSize);
        self::assertSame(25, $config->limitForEachLane);
        self::assertSame(30_000, $config->laneRefreshMs);
        self::assertNull($config->laneFilter);
        self::assertSame(259_200_000, $config->maxAgeMs, '3 days');
        self::assertSame(60_000, $config->expiredCheckIntervalMs);
        self::assertSame(7, $config->retentionDays);
        self::assertSame(200, $config->idleSleepMs);
    }

    public function testAcceptsTheValueOneForEachNumber(): void
    {
        $config = new DeliveryLoopConfig(1, 1, 1, 1, ['crm'], 1, 1, 1, 1);

        self::assertSame(['crm'], $config->laneFilter);
        self::assertSame(1, $config->idleSleepMs);
    }

    /** @return iterable<string, array{string}> */
    public static function numbers(): iterable
    {
        foreach (['leaseMs', 'batchSize', 'limitForEachLane', 'laneRefreshMs', 'maxAgeMs', 'expiredCheckIntervalMs', 'retentionDays', 'idleSleepMs'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('numbers')]
    public function testRejectsANumberBelowOne(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("\${$name} must be 1 or more, 0 given");

        new DeliveryLoopConfig(...[
            $name => 0,
        ]);
    }

    public function testRejectsAnEmptyLaneFilter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$laneFilter must name one or more lanes, or be null');

        new DeliveryLoopConfig(laneFilter: []);
    }
}
