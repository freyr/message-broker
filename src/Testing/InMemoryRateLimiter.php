<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\PermitDivision;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimiter;
use Freyr\MessageBroker\Webhook\RateLimits;

/**
 * In-memory token buckets that implement the contract of {@see RateLimiter}:
 * one global bucket and one bucket for each lane with a limit. A new bucket
 * starts full; the rates come from each call. The refill and the division
 * are those of {@see PermitDivision}, and the time comes from symfony/clock
 * through {@see EpochMillis}. With a null global rate it has no global bucket.
 *
 * It records each acquire and each give-back for the assertions of a test.
 */
final class InMemoryRateLimiter implements RateLimiter
{
    /** @var list<array{wanted: array<array-key, int>, total: int, granted: array<array-key, int>}> each acquire() */
    public array $acquires = [];

    /** @var list<array<array-key, int>> the unused counts of each giveBack() */
    public array $givenBack = [];

    /** @var (callable(int): void)|null called at the start of each acquire() with the call number */
    public $onAcquire;

    /** @var array{tokens: float, at: int}|null */
    private ?array $global = null;

    /** @var array<string, array{tokens: float, at: int}> */
    private array $lanes = [];

    public function acquire(array $wanted, int $total, RateLimits $limits): array
    {
        if ($this->onAcquire !== null) {
            ($this->onAcquire)(count($this->acquires) + 1);
        }
        $now = EpochMillis::now();

        $laneTokens = [];
        $available = [];
        foreach ($wanted as $lane => $count) {
            $lane = (string) $lane;
            $rate = $limits->forLane($lane);
            if ($rate === null) {
                $available[$lane] = $count;
                continue;
            }
            $laneTokens[$lane] = self::tokens($this->lanes[$lane] ?? null, $rate, $now);
            $available[$lane] = min($count, (int) floor($laneTokens[$lane]));
        }

        $globalGrant = $total;
        $globalTokens = 0.0;
        if ($limits->global !== null) {
            $globalTokens = self::tokens($this->global, $limits->global, $now);
            $globalGrant = min($total, (int) floor($globalTokens));
        }

        $granted = PermitDivision::divide($available, $globalGrant);

        foreach ($laneTokens as $lane => $tokens) {
            $this->lanes[$lane] = [
                'tokens' => $tokens - $granted[$lane],
                'at' => $now,
            ];
        }
        if ($limits->global !== null) {
            $this->global = [
                'tokens' => $globalTokens - array_sum($granted),
                'at' => $now,
            ];
        }

        $this->acquires[] = [
            'wanted' => $wanted,
            'total' => $total,
            'granted' => $granted,
        ];

        return $granted;
    }

    public function giveBack(array $unused, RateLimits $limits): void
    {
        $this->givenBack[] = $unused;
        $now = EpochMillis::now();

        foreach ($unused as $lane => $count) {
            $lane = (string) $lane;
            $rate = $limits->forLane($lane);
            if ($rate !== null) {
                $this->lanes[$lane] = [
                    'tokens' => min($rate->burst, self::tokens($this->lanes[$lane] ?? null, $rate, $now) + $count),
                    'at' => $now,
                ];
            }
        }
        if ($limits->global !== null) {
            $this->global = [
                'tokens' => min(
                    $limits->global->burst,
                    self::tokens($this->global, $limits->global, $now) + array_sum($unused),
                ),
                'at' => $now,
            ];
        }
    }

    /** @param array{tokens: float, at: int}|null $bucket null = a new bucket, which starts full */
    private static function tokens(?array $bucket, Rate $rate, int $now): float
    {
        if ($bucket === null) {
            return $rate->burst;
        }

        return PermitDivision::refill($bucket['tokens'], $bucket['at'], $now, $rate);
    }
}
