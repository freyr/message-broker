<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use InvalidArgumentException;

/**
 * The rate of one token bucket of the {@see RateLimiter}: the permits that it
 * refills each second, and the burst capacity, the most permits that it
 * holds. The global bucket and each lane bucket use this value. There is no
 * value for "no limit": a lane with no limit has no Rate, and a deployment
 * with no global limit has a null global Rate ({@see RateLimits}).
 */
final readonly class Rate
{
    /** @var positive-int */
    public int $burst;

    /**
     * @param int $perSecond the requests each second, 1 or more
     * @param int|null $burst the burst capacity, 1 or more; null = one tenth of the rate, with a minimum of 1
     */
    public function __construct(
        public int $perSecond,
        ?int $burst = null,
    ) {
        if ($perSecond < 1) {
            throw new InvalidArgumentException("\$perSecond must be 1 or more, {$perSecond} given");
        }
        $burst ??= max(1, intdiv($perSecond, 10));
        if ($burst < 1) {
            throw new InvalidArgumentException("\$burst must be 1 or more, {$burst} given");
        }
        $this->burst = $burst;
    }
}
