<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Retry;

/**
 * Capped exponential backoff. Pure delay math — where the delay is applied
 * is transport-specific (outbox available_at bump, AMQP TTL wait queue,
 * SQS visibility timeout).
 */
final readonly class Backoff
{
    private function __construct(
        private int $initialDelayMs,
        private int $maxDelayMs,
        private float $multiplier,
    ) {}

    public static function exponential(int $initialDelayMs, int $maxDelayMs, float $multiplier = 4.0): self
    {
        return new self($initialDelayMs, $maxDelayMs, $multiplier);
    }

    public function delayForAttempt(int $attempt): int
    {
        $attempt = max(1, $attempt);

        return (int) min($this->initialDelayMs * $this->multiplier ** ($attempt - 1), (float) $this->maxDelayMs);
    }
}
