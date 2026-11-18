<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Outbox;

use Freyr\MessageBroker\Outbox\LaneLimits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LaneLimitsTest extends TestCase
{
    public function testNullMeansNoLimit(): void
    {
        $limits = new LaneLimits();

        self::assertNull($limits->maxBodyBytes);
        self::assertNull($limits->maxHeaderBytes);
    }

    public function testAcceptsTheSmallestLimit(): void
    {
        $limits = new LaneLimits(maxBodyBytes: 1, maxHeaderBytes: 1);

        self::assertSame(1, $limits->maxBodyBytes);
        self::assertSame(1, $limits->maxHeaderBytes);
    }

    /** @return iterable<string, array{int|null, int|null, string}> */
    public static function invalidLimits(): iterable
    {
        yield 'body zero' => [0, null, 'maxBodyBytes'];
        yield 'body negative' => [-5, null, 'maxBodyBytes'];
        yield 'headers zero' => [null, 0, 'maxHeaderBytes'];
        yield 'headers negative' => [null, -1, 'maxHeaderBytes'];
    }

    #[DataProvider('invalidLimits')]
    public function testRejectsAValueBelowOne(?int $body, ?int $headers, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field.' must be at least 1');

        new LaneLimits(maxBodyBytes: $body, maxHeaderBytes: $headers);
    }
}
