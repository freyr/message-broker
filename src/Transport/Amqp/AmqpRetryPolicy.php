<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\RetryDecision;
use Throwable;

/**
 * Default consumer-side retry policy for AMQP. Userland can replace it with
 * any class exposing decide() — policies are per-transport because the
 * delay mechanics are (AMQP: TTL wait queue + DLX; SQS: visibility timeout;
 * Kafka: pause/seek or retry topics).
 */
final readonly class AmqpRetryPolicy
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
