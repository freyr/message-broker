<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Closure;
use Freyr\MessageBroker\Webhook\RateLimiter;
use Freyr\MessageBroker\Webhook\RateLimits;
use Throwable;

/**
 * A rate limiter whose grants come from a closure (by default each wanted
 * count), with no bucket. It records the arguments of each acquire() and
 * giveBack() call, also the RateLimits, and can throw from either.
 */
final class StubRateLimiter implements RateLimiter
{
    /** @var list<array{wanted: array<array-key, int>, total: int, limits: RateLimits}> */
    public array $acquires = [];

    /** @var list<array{unused: array<array-key, int>, limits: RateLimits}> */
    public array $givenBack = [];

    public ?Throwable $giveBackError = null;

    /** @var Closure(array<array-key, int>, int): array<array-key, int> */
    private readonly Closure $grant;

    /** @param (Closure(array<array-key, int>, int): array<array-key, int>)|null $grant wanted, total => grants */
    public function __construct(?Closure $grant = null)
    {
        $this->grant = $grant ?? static fn (array $wanted, int $total): array => $wanted;
    }

    public function acquire(array $wanted, int $total, RateLimits $limits): array
    {
        $this->acquires[] = compact('wanted', 'total', 'limits');

        return ($this->grant)($wanted, $total);
    }

    public function giveBack(array $unused, RateLimits $limits): void
    {
        $this->givenBack[] = compact('unused', 'limits');
        if ($this->giveBackError !== null) {
            throw $this->giveBackError;
        }
    }
}
