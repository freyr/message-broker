<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Webhook\CircuitBreakerPolicy;
use Freyr\MessageBroker\Webhook\DeliveryResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerPolicyTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $policy = new CircuitBreakerPolicy();

        self::assertSame(300_000, $policy->windowMs, '5 minutes');
        self::assertSame(10, $policy->minimumRequests);
        self::assertSame(70, $policy->errorRatePercent);
        self::assertSame(600_000, $policy->openMs, '10 minutes');
        self::assertSame(5_000, $policy->slowLimitMs, '5 seconds');
    }

    public function testAcceptsTheSmallestValues(): void
    {
        $policy = new CircuitBreakerPolicy(1, 1, 1, 1, 0);

        self::assertSame(1, $policy->windowMs);
        self::assertSame(1, $policy->minimumRequests);
        self::assertSame(1, $policy->errorRatePercent);
        self::assertSame(1, $policy->openMs);
        self::assertSame(0, $policy->slowLimitMs);
    }

    public function testAcceptsAnErrorRateOfOneHundredPercent(): void
    {
        self::assertSame(100, (new CircuitBreakerPolicy(errorRatePercent: 100))->errorRatePercent);
    }

    /** @return iterable<string, array{array<string, int>, string}> */
    public static function invalidValues(): iterable
    {
        yield 'window 0' => [[
            'windowMs' => 0,
        ], '$windowMs must be 1 or more, 0 given'];
        yield 'minimum requests 0' => [[
            'minimumRequests' => 0,
        ], '$minimumRequests must be 1 or more, 0 given'];
        yield 'open time 0' => [[
            'openMs' => 0,
        ], '$openMs must be 1 or more, 0 given'];
        yield 'error rate 0' => [[
            'errorRatePercent' => 0,
        ], '$errorRatePercent must be 1 to 100, 0 given'];
        yield 'error rate 101' => [[
            'errorRatePercent' => 101,
        ], '$errorRatePercent must be 1 to 100, 101 given'];
        yield 'slow limit -1' => [[
            'slowLimitMs' => -1,
        ], '$slowLimitMs must be 0 or more, -1 given'];
    }

    /** @param array<string, int> $arguments */
    #[DataProvider('invalidValues')]
    public function testRejectsAValueOutOfItsRange(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new CircuitBreakerPolicy(...$arguments);
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function tripCases(): iterable
    {
        // The defaults: a minimum of 10 requests and an error rate of 70%.
        yield '10 requests, 7 failures' => [10, 7, true];
        yield '10 requests, 6 failures' => [10, 6, false];
        yield '9 requests, 9 failures' => [9, 9, false];
        yield '11 requests, 8 failures (72.7%)' => [11, 8, true];
        yield '11 requests, 7 failures (63.6%)' => [11, 7, false];
        yield '20 requests, 14 failures (70%)' => [20, 14, true];
        yield '20 requests, 13 failures (65%)' => [20, 13, false];
        yield '10 requests, 10 failures' => [10, 10, true];
        yield '10 requests, 0 failures' => [10, 0, false];
    }

    #[DataProvider('tripCases')]
    public function testTheTripRule(int $requests, int $failures, bool $trips): void
    {
        self::assertSame($trips, (new CircuitBreakerPolicy())->trips($requests, $failures));
    }

    public function testTheTripRuleUsesTheConfiguredValues(): void
    {
        $policy = new CircuitBreakerPolicy(minimumRequests: 3, errorRatePercent: 50);

        self::assertTrue($policy->trips(3, 2));
        self::assertTrue($policy->trips(4, 2), 'exactly 50%');
        self::assertFalse($policy->trips(5, 2));
        self::assertFalse($policy->trips(2, 2), 'below the minimum');
    }

    /** @return iterable<string, array{DeliveryResult, bool}> */
    public static function failureCases(): iterable
    {
        yield 'delivered fast' => [DeliveryResult::delivered(1), false];
        yield 'delivered at the slow limit' => [DeliveryResult::delivered(5_000), false];
        yield 'delivered one millisecond above the slow limit' => [DeliveryResult::delivered(5_001), true];
        yield 'failed' => [DeliveryResult::failed(1, '500'), true];
        yield 'refused' => [DeliveryResult::refused('gone', 1, '410'), true];
    }

    #[DataProvider('failureCases')]
    public function testWhatCountsAsAFailure(DeliveryResult $result, bool $failure): void
    {
        self::assertSame($failure, (new CircuitBreakerPolicy())->countsAsFailure($result));
    }

    public function testASlowLimitOfZeroTurnsTheSlowRuleOff(): void
    {
        $policy = new CircuitBreakerPolicy(slowLimitMs: 0);

        self::assertFalse($policy->countsAsFailure(DeliveryResult::delivered(1)));
        self::assertFalse($policy->countsAsFailure(DeliveryResult::delivered(3_600_000)));
        self::assertTrue($policy->countsAsFailure(DeliveryResult::failed(1, '500')));
    }

    public function testASlowLimitOfOneMillisecond(): void
    {
        $policy = new CircuitBreakerPolicy(slowLimitMs: 1);

        self::assertFalse($policy->countsAsFailure(DeliveryResult::delivered(0)));
        self::assertFalse($policy->countsAsFailure(DeliveryResult::delivered(1)));
        self::assertTrue($policy->countsAsFailure(DeliveryResult::delivered(2)));
    }
}
