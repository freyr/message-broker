<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

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
    public function __construct(
        private int $maxAttempts = 5,
        private int $initialDelayMs = 1_000,
        private float $multiplier = 4.0,
        private int $maxDelayMs = 300_000,
    ) {}

    public function decide(int $attempt, Throwable $error): RetryDecision
    {
        if ($attempt >= $this->maxAttempts) {
            return RetryDecision::deadLetter();
        }

        $delay = (int) min($this->initialDelayMs * ($this->multiplier ** ($attempt - 1)), $this->maxDelayMs);

        return RetryDecision::retryIn($delay);
    }
}
