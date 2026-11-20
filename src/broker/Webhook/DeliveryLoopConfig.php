<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use InvalidArgumentException;

/**
 * The configuration of one {@see DeliveryLoop}. Each number is 1 or more.
 * The retry attempts and delays are the configuration of the retry policy;
 * the values of the circuit breaker are the configuration of the
 * {@see CircuitBreakerPolicy} of the breaker. The rates go to each
 * {@see RateLimiter} call; {@see NoRateLimit} ignores them.
 */
final readonly class DeliveryLoopConfig
{
    /**
     * @param int $leaseMs the lease of a claim; must be longer than DeliverySender::maxSendMs()
     * @param int $batchSize the most rows of one claim, all lanes together
     * @param int $limitForEachLane the most rows of one lane in one claim
     * @param int $laneRefreshMs the interval of the read of SubscriptionProvider::lanes()
     * @param list<string>|null $laneFilter claim only these lanes; null = all lanes
     * @param int $idleSleepMs the sleep after a pass that delivered nothing
     * @param int $permitWaitMs the sleep after a grant of 0 permits of the rate limiter
     * @param Rate|null $globalRate the rate of the global bucket, for all lanes and all workers together;
     *                             null = no global bucket. When set, the loop refuses to start with a
     *                             batch size larger than its burst capacity
     * @param Rate|null $defaultLaneRate the rate of a lane whose subscription has no rate
     *                                  (Subscription::$rateLimit); null = no lane limit for such a lane
     */
    public function __construct(
        public int $leaseMs = 60_000,
        public int $batchSize = 100,
        public int $limitForEachLane = 25,
        public int $laneRefreshMs = 30_000,
        public ?array $laneFilter = null,
        public int $idleSleepMs = 200,
        public int $permitWaitMs = 50,
        public ?Rate $globalRate = null,
        public ?Rate $defaultLaneRate = null,
    ) {
        foreach ([
            'leaseMs' => $leaseMs,
            'batchSize' => $batchSize,
            'limitForEachLane' => $limitForEachLane,
            'laneRefreshMs' => $laneRefreshMs,
            'idleSleepMs' => $idleSleepMs,
            'permitWaitMs' => $permitWaitMs,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("\${$name} must be 1 or more, {$value} given");
            }
        }
        if ($laneFilter === []) {
            throw new InvalidArgumentException('$laneFilter must name one or more lanes, or be null');
        }
    }
}
