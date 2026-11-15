<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox;

use DateTimeImmutable;
use Freyr\MessageBroker\Outbox\PartitionPurgeReport;
use PHPUnit\Framework\TestCase;

final class PartitionPurgeReportTest extends TestCase
{
    public function testCountsDroppedAndKeptRowsSeparately(): void
    {
        $report = new PartitionPurgeReport(
            new DateTimeImmutable('2026-03-03'),
            ['a', 'b'],
            [
                'a' => 2,
                'b' => 3,
            ],
            [
                'c' => 4,
                'd' => 1,
            ],
        );

        self::assertSame(5, $report->unpublishedRowsDropped());
        self::assertSame(5, $report->unpublishedRowsKept());
        self::assertTrue($report->hasKept());
    }

    public function testNothingIsKeptByDefault(): void
    {
        $report = new PartitionPurgeReport(new DateTimeImmutable('2026-03-03'), ['a'], []);

        self::assertSame([], $report->kept);
        self::assertSame(0, $report->unpublishedRowsKept());
        self::assertSame(0, $report->unpublishedRowsDropped());
        self::assertFalse($report->hasKept());
    }
}
