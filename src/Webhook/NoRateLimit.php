<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * A {@see RateLimiter} with no bucket: it ignores all rates and divides
 * $total between the wanted counts with the max-min rule of
 * {@see PermitDivision}, so the sum of its grants is at most $total.
 */
final class NoRateLimit implements RateLimiter
{
    public function acquire(array $wanted, int $total, RateLimits $limits): array
    {
        return PermitDivision::divide($wanted, $total);
    }

    public function giveBack(array $unused, RateLimits $limits): void {}
}
