<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Webhook\PermitDivision;
use Freyr\MessageBroker\Webhook\Rate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PermitDivisionTest extends TestCase
{
    /** @return iterable<string, array{array<string, int>, int, array<string, int>}> */
    public static function divisions(): iterable
    {
        // The fixed vectors of the spec.
        yield 'spec: 10 for [8, 2, 5]' => [[
            'a' => 8,
            'b' => 2,
            'c' => 5,
        ], 10, [
            'a' => 4,
            'b' => 2,
            'c' => 4,
        ]];
        yield 'spec: 11 for [8, 2, 5], the rest goes to the first lane above the level' => [[
            'a' => 8,
            'b' => 2,
            'c' => 5,
        ], 11, [
            'a' => 5,
            'b' => 2,
            'c' => 4,
        ]];
        yield 'the rest in the order of the call, not of the size' => [[
            'c' => 5,
            'b' => 2,
            'a' => 8,
        ], 11, [
            'c' => 5,
            'b' => 2,
            'a' => 4,
        ]];
        yield 'a rest of 2 for three lanes above the level' => [[
            'a' => 9,
            'b' => 9,
            'c' => 9,
        ], 11, [
            'a' => 4,
            'b' => 4,
            'c' => 3,
        ]];
        yield 'a lane at the level gets no permit of the rest' => [[
            'a' => 4,
            'b' => 9,
            'c' => 9,
        ], 13, [
            'a' => 4,
            'b' => 5,
            'c' => 4,
        ]];
        yield 'the sum equals the grant: each lane gets its availability' => [[
            'a' => 8,
            'b' => 2,
            'c' => 5,
        ], 15, [
            'a' => 8,
            'b' => 2,
            'c' => 5,
        ]];
        yield 'the sum is below the grant' => [[
            'a' => 1,
            'b' => 3,
        ], 100, [
            'a' => 1,
            'b' => 3,
        ]];
        yield 'one permit for two lanes' => [[
            'a' => 3,
            'b' => 3,
        ], 1, [
            'a' => 1,
            'b' => 0,
        ]];
        yield 'a grant of 0' => [[
            'a' => 3,
            'b' => 3,
        ], 0, [
            'a' => 0,
            'b' => 0,
        ]];
        yield 'a lane with an availability of 0' => [[
            'a' => 0,
            'b' => 7,
        ], 5, [
            'a' => 0,
            'b' => 5,
        ]];
        yield 'no lane' => [[], 10, []];
    }

    /**
     * @param array<string, int> $available
     * @param array<string, int> $expected
     */
    #[DataProvider('divisions')]
    public function testDividesTheGrantMaxMinFairInWholePermits(array $available, int $grant, array $expected): void
    {
        self::assertSame($expected, PermitDivision::divide($available, $grant));
    }

    public function testKeepsANumericLaneIdAsTheKeyOfItsGrant(): void
    {
        $grants = PermitDivision::divide([
            '42' => 6,
            'crm' => 6,
        ], 4);

        self::assertSame([
            '42' => 2,
            'crm' => 2,
        ], $grants);
        self::assertSame(2, $grants['42']);
    }

    public function testTheRefillAddsTheRateForTheElapsedTime(): void
    {
        $rate = new Rate(10, 50);

        self::assertSame(3.5, PermitDivision::refill(1.0, 1_000, 1_250, $rate), '250 ms at 10 each second');
        self::assertSame(1.01, PermitDivision::refill(1.0, 1_000, 1_001, $rate), '1 ms at 10 each second');
        self::assertSame(1.0, PermitDivision::refill(1.0, 1_000, 1_000, $rate), 'no time, no refill');
    }

    public function testTheRefillIsAtMostTheBurst(): void
    {
        $rate = new Rate(10, 5);

        self::assertSame(5.0, PermitDivision::refill(1.0, 1_000, 1_400, $rate), 'exactly full after 400 ms');
        self::assertSame(5.0, PermitDivision::refill(1.0, 1_000, 61_000, $rate));
        self::assertSame(5.0, PermitDivision::refill(5.0, 1_000, 1_001, $rate), 'a full bucket stays full');
    }

    public function testATimeEarlierThanTheStoredTimeGivesNoRefill(): void
    {
        $rate = new Rate(10, 50);

        self::assertSame(2.5, PermitDivision::refill(2.5, 5_000, 4_000, $rate));
    }
}
