<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/** The circuit breaker state of one lane, from {@see LaneStates::all()}. The times are epoch milliseconds. */
final readonly class LaneState
{
    /**
     * @param int $windowStartedAt the start of the present count window
     * @param int $requests the requests with a result in the window
     * @param int $failures the results in the window that count as a failure
     * @param int|null $openUntil the time until which the lane is out of circulation; null when it never was, or after a close
     * @param int $openedCount the number of times that the lane left circulation
     */
    public function __construct(
        public string $lane,
        public int $windowStartedAt,
        public int $requests,
        public int $failures,
        public ?int $openUntil,
        public int $openedCount,
    ) {}
}
