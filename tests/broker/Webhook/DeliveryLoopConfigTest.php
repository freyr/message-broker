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
        self::assertSame(200, $config->idleSleepMs);
        self::assertSame(50, $config->permitWaitMs);
        self::assertNull($config->burstCapacity, 'no burst capacity: the loop does not check the batch size');
    }

    public function testAcceptsTheValueOneForThePermitWaitAndTheBurstCapacity(): void
    {
        $config = new DeliveryLoopConfig(permitWaitMs: 1, burstCapacity: 1);

        self::assertSame(1, $config->permitWaitMs);
        self::assertSame(1, $config->burstCapacity);
    }

    public function testAcceptsTheValueOneForEachNumber(): void
    {
        $config = new DeliveryLoopConfig(1, 1, 1, 1, ['crm'], 1);

        self::assertSame(['crm'], $config->laneFilter);
        self::assertSame(1, $config->idleSleepMs);
    }

    /** @return iterable<string, array{string}> */
    public static function numbers(): iterable
    {
        foreach (['leaseMs', 'batchSize', 'limitForEachLane', 'laneRefreshMs', 'idleSleepMs', 'permitWaitMs', 'burstCapacity'] as $name) {
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
