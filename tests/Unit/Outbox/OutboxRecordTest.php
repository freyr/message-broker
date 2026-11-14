<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox;

use Freyr\MessageBroker\Outbox\OutboxRecord;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboxRecordTest extends TestCase
{
    public function testAcceptsValuesAtTheColumnLimits(): void
    {
        $record = self::record(lane: str_repeat('l', 64), name: str_repeat('ń', 255));

        self::assertSame(64, mb_strlen($record->lane));
        self::assertSame(255, mb_strlen($record->messageName), 'limits count characters, not bytes');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty lane' => ['', 'order.placed', 'Outbox lane must be 1 to 64 characters'];
        yield 'over-long lane' => [str_repeat('l', 65), 'order.placed', 'Outbox lane must be 1 to 64 characters'];
        yield 'empty name' => ['orders', '', 'Outbox message name must be 1 to 255 characters'];
        yield 'over-long name' => ['orders', str_repeat('n', 256), 'Outbox message name must be 1 to 255 characters'];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsValuesOutsideTheColumnLimits(string $lane, string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::record(lane: $lane, name: $name);
    }

    private static function record(string $lane, string $name): OutboxRecord
    {
        return new OutboxRecord(
            id: '01900000-0000-7000-8000-000000000001',
            lane: $lane,
            messageName: $name,
            body: '{}',
            headers: [],
            createdAt: 1_749_722_400_123,
        );
    }
}
