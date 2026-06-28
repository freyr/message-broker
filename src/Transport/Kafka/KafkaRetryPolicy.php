<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Kafka;

use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\RetryDecision;
use Throwable;

/**
 * Default consumer-side retry policy for Kafka. Same decision shape as the
 * AMQP policy; the delay is enacted differently — the Kafka consumer retries
 * in process (the partition stays blocked, preserving per-key FIFO) rather
 * than republishing to a TTL wait queue.
 */
final readonly class KafkaRetryPolicy
{
    private Backoff $backoff;

    public function __construct(
        private int $maxAttempts = 5,
        ?Backoff $backoff = null,
    ) {
        $this->backoff = $backoff ?? Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000);
    }

    public function decide(int $attempt, Throwable $error): RetryDecision
    {
        if ($attempt >= $this->maxAttempts) {
            return RetryDecision::deadLetter();
        }

        return RetryDecision::retryIn($this->backoff->delayForAttempt($attempt));
    }
}
