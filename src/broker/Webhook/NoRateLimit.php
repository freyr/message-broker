<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/** A {@see RateLimiter} that limits nothing: it grants each wanted permit. The default of {@see DeliveryLoop}. */
final class NoRateLimit implements RateLimiter
{
    public function acquire(int $wanted): int
    {
        return $wanted;
    }

    public function giveBack(int $count): void {}
}
