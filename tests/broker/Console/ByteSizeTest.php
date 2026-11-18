<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Console;

use Freyr\MessageBroker\Console\ByteSize;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ByteSizeTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function validSizes(): iterable
    {
        yield 'plain bytes' => ['512', 512];
        yield 'one byte' => ['1', 1];
        yield 'kilobytes' => ['2K', 2_048];
        yield 'megabytes' => ['128M', 134_217_728];
        yield 'gigabytes' => ['3G', 3_221_225_472];
        yield 'lower case k' => ['2k', 2_048];
        yield 'lower case m' => ['128m', 134_217_728];
        yield 'lower case g' => ['3g', 3_221_225_472];
        yield 'one kilobyte' => ['1K', 1_024];
    }

    #[DataProvider('validSizes')]
    public function testParsesToBytes(string $size, int $expected): void
    {
        self::assertSame($expected, ByteSize::toBytes($size));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSizes(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown unit' => ['12X'];
        yield 'negative' => ['-1'];
        yield 'zero' => ['0'];
        yield 'zero with unit' => ['0M'];
        yield 'no number' => ['M'];
        yield 'two units' => ['1MB'];
        yield 'leading text' => ['x12M'];
        yield 'trailing text' => ['12Mx'];
        yield 'fraction' => ['1.5M'];
        yield 'space' => ['12 M'];
    }

    #[DataProvider('invalidSizes')]
    public function testRejectsAnInvalidSize(string $size): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid memory limit '{$size}'");

        ByteSize::toBytes($size);
    }
}
