<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\Duration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function validDurations(): iterable
    {
        yield 'seconds' => ['30s', 30_000];
        yield 'minutes' => ['15m', 900_000];
        yield 'hours' => ['24h', 86_400_000];
        yield 'days' => ['7d', 604_800_000];
        yield 'one day' => ['1d', 86_400_000];
        yield 'one hour' => ['1h', 3_600_000];
        yield 'one minute' => ['1m', 60_000];
        yield 'one second' => ['1s', 1_000];
        yield 'zero' => ['0s', 0];
    }

    #[DataProvider('validDurations')]
    public function testParsesToMilliseconds(string $duration, int $expected): void
    {
        self::assertSame($expected, Duration::toMilliseconds($duration));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDurations(): iterable
    {
        yield 'empty' => [''];
        yield 'no unit' => ['7'];
        yield 'unknown unit' => ['7w'];
        yield 'no number' => ['d'];
        yield 'leading text' => ['x7d'];
        yield 'trailing text' => ['7dx'];
        yield 'negative' => ['-7d'];
        yield 'two units' => ['1h30m'];
    }

    #[DataProvider('invalidDurations')]
    public function testRejectsInvalidDuration(string $duration): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid duration '{$duration}'");

        Duration::toMilliseconds($duration);
    }
}
