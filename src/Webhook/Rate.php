<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use InvalidArgumentException;

/**
 * The rate of one token bucket of the {@see RateLimiter}: the permits that it
 * refills each second (above 0, also below 1, for example 0.5 for 30 each
 * minute), and the burst capacity, the most whole permits that it holds. The
 * global bucket and each lane bucket use this value. There is no value for
 * "no limit": a lane with no limit has no Rate, and a deployment with no
 * global limit has a null global Rate ({@see RateLimits}).
 */
final readonly class Rate
{
    /** @var positive-int */
    public int $burst;

    /**
     * @param float $perSecond the requests each second, above 0 and finite
     * @param int|null $burst the burst capacity, 1 or more; null = one tenth of the rate as a whole number, with a minimum of 1
     */
    public function __construct(
        public float $perSecond,
        ?int $burst = null,
    ) {
        if (!($perSecond > 0) || is_infinite($perSecond)) {
            // var_export: PHP 8.5 warns when NAN is coerced to a string
            throw new InvalidArgumentException('$perSecond must be above 0 and finite, '.var_export($perSecond, true).' given');
        }
        $burst ??= max(1, (int) floor($perSecond / 10));
        if ($burst < 1) {
            throw new InvalidArgumentException("\$burst must be 1 or more, {$burst} given");
        }
        $this->burst = $burst;
    }
}
