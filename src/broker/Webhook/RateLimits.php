<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * The rates of one {@see RateLimiter} call: the global rate, and the rate of
 * each lane with a limit. The store holds no rate; each call gives them.
 *
 * A null global rate means no global bucket: the store then reads and writes
 * no global state, and only the total of the call caps the sum of the grants.
 * A lane that is not in the map has no lane limit.
 */
final readonly class RateLimits
{
    /**
     * @param Rate|null $global the rate of the global bucket; null = no global bucket
     * @param array<string, Rate> $lanes the rate of each lane with a limit, by lane ID
     */
    public function __construct(
        public ?Rate $global,
        public array $lanes = [],
    ) {}

    /** The rate of the lane, or null when the lane has no limit. */
    public function forLane(string $lane): ?Rate
    {
        return $this->lanes[$lane] ?? null;
    }
}
