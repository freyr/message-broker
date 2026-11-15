<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Retry;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Throwable;

/**
 * Default retry policy: retries with a backoff delay until the attempt budget
 * is used, then dead-letters. It ignores the message and the error.
 */
final readonly class ExponentialRetryPolicy implements RetryPolicy
{
    private Backoff $backoff;

    public function __construct(
        private int $maxAttempts = 5,
        ?Backoff $backoff = null,
    ) {
        $this->backoff = $backoff ?? Backoff::exponential(initialDelayMs: 1_000, maxDelayMs: 300_000);
    }

    public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
    {
        if ($attempt >= $this->maxAttempts) {
            return RetryDecision::deadLetter();
        }

        return RetryDecision::retryIn($this->backoff->delayForAttempt($attempt));
    }
}
