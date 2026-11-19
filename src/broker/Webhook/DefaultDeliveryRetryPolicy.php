<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use Freyr\MessageBroker\Time\EpochMillis;

/**
 * The default retry policy of webhook deliveries: a fixed schedule of delays.
 * The number of attempts is the number of delays plus one; the default
 * schedule (the Svix schedule) has 8 attempts over about 27.5 hours.
 *
 *  - A refused result is a dead letter at once.
 *  - A failed result of the last attempt is a dead letter.
 *  - Else the retry time is now plus the delay of the attempt. A retry time
 *    from the receiver replaces it when it is later, cut to now plus
 *    $maxReceiverDelayMs.
 *
 * The time comes from symfony/clock through {@see EpochMillis}.
 */
final readonly class DefaultDeliveryRetryPolicy implements DeliveryRetryPolicy
{
    /** The delay after the failed attempts 1 to 7: 5 s, 5 min, 30 min, 2 h, 5 h, 10 h, 10 h. */
    public const array SCHEDULE_MS = [5_000, 300_000, 1_800_000, 7_200_000, 18_000_000, 36_000_000, 36_000_000];

    /**
     * @param list<int> $delaysMs the delay after each failed attempt but the last, in milliseconds
     * @param int $maxReceiverDelayMs the longest delay that a retry time of the receiver can give
     */
    public function __construct(
        private array $delaysMs = self::SCHEDULE_MS,
        private int $maxReceiverDelayMs = 86_400_000,
    ) {}

    public function decide(int $attempt, DeliveryResult $result, DeliveryRecord $delivery): DeliveryDecision
    {
        if ($result->kind === DeliveryResultKind::Refused || $attempt > count($this->delaysMs)) {
            return DeliveryDecision::deadLetter();
        }

        $now = EpochMillis::now();
        $retryAt = $now + $this->delaysMs[$attempt - 1];
        if ($result->retryAt !== null) {
            $retryAt = max($retryAt, min($result->retryAt, $now + $this->maxReceiverDelayMs));
        }

        return DeliveryDecision::retryAt($retryAt);
    }

    public function longestDelayMs(): int
    {
        return max([$this->maxReceiverDelayMs, ...$this->delaysMs]);
    }
}
