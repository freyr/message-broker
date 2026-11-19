<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use InvalidArgumentException;

/**
 * The configuration of the circuit breaker, for all lanes together. The
 * implementation of {@see CircuitBreaker} uses the window length, the minimum
 * number of requests, the error rate and the open time; {@see DeliveryLoop}
 * uses the slow limit to make the counts. Give both the same value.
 *
 * The defaults: a window of 5 minutes, a minimum of 10 requests and an error
 * rate of 70% (as Convoy), an open time of 10 minutes, and a slow limit of
 * 5 seconds (half of the default request limit of the HTTP sender).
 */
final readonly class CircuitBreakerPolicy
{
    /**
     * @param int $windowMs the length of a count window; an older window starts again from 0
     * @param int $minimumRequests the fewest requests in a window for a trip
     * @param int $errorRatePercent the error rate of a trip: failures divided by requests, 1 to 100
     * @param int $openMs the time that a lane is out of circulation after a trip
     * @param int $slowLimitMs a delivered result with a longer request time counts as a failure; 0 = no slow rule
     */
    public function __construct(
        public int $windowMs = 300_000,
        public int $minimumRequests = 10,
        public int $errorRatePercent = 70,
        public int $openMs = 600_000,
        public int $slowLimitMs = 5_000,
    ) {
        foreach ([
            'windowMs' => $windowMs,
            'minimumRequests' => $minimumRequests,
            'openMs' => $openMs,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("\${$name} must be 1 or more, {$value} given");
            }
        }
        if ($errorRatePercent < 1 || $errorRatePercent > 100) {
            throw new InvalidArgumentException("\$errorRatePercent must be 1 to 100, {$errorRatePercent} given");
        }
        if ($slowLimitMs < 0) {
            throw new InvalidArgumentException("\$slowLimitMs must be 0 or more, {$slowLimitMs} given");
        }
    }

    /** The trip rule: the minimum number of requests or more, and the error rate or more. */
    public function trips(int $requests, int $failures): bool
    {
        return $requests >= $this->minimumRequests && $failures * 100 >= $this->errorRatePercent * $requests;
    }

    /** Each result that is not delivered, and a delivered result above the slow limit. */
    public function countsAsFailure(DeliveryResult $result): bool
    {
        if ($result->kind !== DeliveryResultKind::Delivered) {
            return true;
        }

        return $this->slowLimitMs > 0 && $result->requestMs > $this->slowLimitMs;
    }
}
