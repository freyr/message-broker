<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use InvalidArgumentException;

/** The requests and the failures of one lane in one batch, for {@see CircuitBreaker::record()}. */
final readonly class LaneCounts
{
    public function __construct(
        public string $lane,
        public int $requests,
        public int $failures,
    ) {
        if ($requests < 1) {
            throw new InvalidArgumentException("The counts of the lane \"{$lane}\" need 1 or more requests, {$requests} given");
        }
        if ($failures < 0 || $failures > $requests) {
            throw new InvalidArgumentException("The failures of the lane \"{$lane}\" must be 0 to {$requests}, {$failures} given");
        }
    }
}
