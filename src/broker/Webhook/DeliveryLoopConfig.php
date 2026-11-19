<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use InvalidArgumentException;

/**
 * The configuration of one {@see DeliveryLoop}. Each number is 1 or more.
 * The retry attempts and delays are the configuration of the retry policy.
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
     */
    public function __construct(
        public int $leaseMs = 60_000,
        public int $batchSize = 100,
        public int $limitForEachLane = 25,
        public int $laneRefreshMs = 30_000,
        public ?array $laneFilter = null,
        public int $idleSleepMs = 200,
    ) {
        foreach ([
            'leaseMs' => $leaseMs,
            'batchSize' => $batchSize,
            'limitForEachLane' => $limitForEachLane,
            'laneRefreshMs' => $laneRefreshMs,
            'idleSleepMs' => $idleSleepMs,
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
