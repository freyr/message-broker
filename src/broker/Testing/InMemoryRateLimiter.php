<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Testing;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\RateLimiter;
use InvalidArgumentException;

/**
 * In-memory token bucket that implements the contract of
 * {@see RateLimiter}: the bucket starts full, refills at the rate (permits
 * each second) up to the burst capacity, grants whole permits, and takes
 * unused permits back up to the burst capacity. The burst capacity has a
 * default of one tenth of the rate, and a minimum of 1. The time comes from
 * symfony/clock through {@see EpochMillis}.
 *
 * It records each grant and each give-back for the assertions of a test.
 */
final class InMemoryRateLimiter implements RateLimiter
{
    public readonly int $burstCapacity;

    /** @var list<array{wanted: int, granted: int}> each acquire() */
    public array $acquires = [];

    /** @var list<int> the count of each giveBack() */
    public array $givenBack = [];

    /** @var (callable(int): void)|null called at the start of each acquire() with the call number */
    public $onAcquire;

    private float $tokens;

    private int $refilledAt;

    public function __construct(
        private readonly int $ratePerSecond,
        ?int $burstCapacity = null,
    ) {
        if ($ratePerSecond < 1) {
            throw new InvalidArgumentException("\$ratePerSecond must be 1 or more, {$ratePerSecond} given");
        }
        $burstCapacity ??= max(1, intdiv($ratePerSecond, 10));
        if ($burstCapacity < 1) {
            throw new InvalidArgumentException("\$burstCapacity must be 1 or more, {$burstCapacity} given");
        }
        $this->burstCapacity = $burstCapacity;
        $this->tokens = $burstCapacity;
        $this->refilledAt = EpochMillis::now();
    }

    public function acquire(int $wanted): int
    {
        if ($this->onAcquire !== null) {
            ($this->onAcquire)(count($this->acquires) + 1);
        }
        $this->refill();
        $granted = max(0, min($wanted, (int) floor($this->tokens)));
        $this->tokens -= $granted;
        $this->acquires[] = [
            'wanted' => $wanted,
            'granted' => $granted,
        ];

        return $granted;
    }

    public function giveBack(int $count): void
    {
        $this->givenBack[] = $count;
        $this->refill();
        $this->tokens = min($this->burstCapacity, $this->tokens + $count);
    }

    private function refill(): void
    {
        $now = EpochMillis::now();
        $elapsedMs = max(0, $now - $this->refilledAt);
        $this->tokens = min($this->burstCapacity, $this->tokens + $elapsedMs * $this->ratePerSecond / 1_000);
        $this->refilledAt = $now;
    }
}
