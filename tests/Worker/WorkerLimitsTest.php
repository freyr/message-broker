<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Worker;

use Freyr\MessageBroker\Worker\WorkerLimits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerLimitsTest extends TestCase
{
    public function testAllLimitsAreOffByDefault(): void
    {
        $limits = new WorkerLimits();

        self::assertNull($limits->maxMessages);
        self::assertNull($limits->maxSeconds);
        self::assertNull($limits->maxMemoryBytes);
        self::assertNull($limits->maxFailures);
    }

    public function testEachFieldKeepsItsValue(): void
    {
        $limits = new WorkerLimits(maxMessages: 1, maxSeconds: 2, maxMemoryBytes: 3, maxFailures: 4);

        self::assertSame(1, $limits->maxMessages);
        self::assertSame(2, $limits->maxSeconds);
        self::assertSame(3, $limits->maxMemoryBytes);
        self::assertSame(4, $limits->maxFailures);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidValues(): iterable
    {
        foreach (['maxMessages', 'maxSeconds', 'maxMemoryBytes', 'maxFailures'] as $field) {
            yield "{$field} zero" => [$field, 0];
            yield "{$field} negative" => [$field, -1];
        }
    }

    #[DataProvider('invalidValues')]
    public function testAValueBelowOneThrowsAndNamesTheParameter(string $field, int $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("\${$field} must be 1 or more, {$value} given");

        new WorkerLimits(...[
            $field => $value,
        ]);
    }
}
