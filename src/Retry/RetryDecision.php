<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Retry;

/**
 * What a retry policy decides after a failure. Shared shape; the policies
 * themselves are transport-specific (each transport implements retry
 * delays its native way).
 */
final readonly class RetryDecision
{
    private function __construct(
        public RetryAction $action,
        public int $delayMs = 0,
    ) {}

    public static function retryIn(int $delayMs): self
    {
        return new self(RetryAction::Retry, $delayMs);
    }

    public static function deadLetter(): self
    {
        return new self(RetryAction::DeadLetter);
    }

    public static function discard(): self
    {
        return new self(RetryAction::Discard);
    }
}
