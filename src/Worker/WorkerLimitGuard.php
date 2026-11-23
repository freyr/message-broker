<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Worker;

use Closure;
use Freyr\MessageBroker\Time\EpochMillis;

/**
 * Counts the work of one worker run and answers which limit, if any, is
 * reached. The time comes from symfony/clock through {@see EpochMillis}, so a
 * test sets a MockClock; the memory source is a closure for the same reason.
 * With no limit set, {@see stopReason()} reads no memory.
 */
final class WorkerLimitGuard
{
    private int $messages = 0;

    private int $failures = 0;

    private int $startedAtMs = 0;

    /** @var Closure(): int */
    private readonly Closure $memoryUsage;

    /** @param (Closure(): int)|null $memoryUsage bytes in use; default memory_get_usage(true) */
    public function __construct(
        private readonly WorkerLimits $limits,
        ?Closure $memoryUsage = null,
    ) {
        $this->memoryUsage = $memoryUsage ?? static fn (): int => memory_get_usage(true);
    }

    /** Record the start time and set the counts to zero. */
    public function start(): void
    {
        $this->messages = 0;
        $this->failures = 0;
        $this->startedAtMs = EpochMillis::now();
    }

    public function recordMessages(int $count): void
    {
        $this->messages += $count;
    }

    public function recordFailure(): void
    {
        ++$this->failures;
    }

    /** The first limit that is reached, in the order messages, failures, memory, time; else null. */
    public function stopReason(): ?WorkerStopReason
    {
        if ($this->limits->maxMessages !== null && $this->messages >= $this->limits->maxMessages) {
            return WorkerStopReason::MessageLimit;
        }
        if ($this->limits->maxFailures !== null && $this->failures >= $this->limits->maxFailures) {
            return WorkerStopReason::FailureLimit;
        }
        if ($this->limits->maxMemoryBytes !== null && $this->usedMemory() >= $this->limits->maxMemoryBytes) {
            return WorkerStopReason::MemoryLimit;
        }
        if ($this->limits->maxSeconds !== null && $this->elapsedMs() >= $this->limits->maxSeconds * 1_000) {
            return WorkerStopReason::TimeLimit;
        }

        return null;
    }

    /** Seconds until the time limit, never below 0; null when no time limit is set. */
    public function secondsLeft(): ?float
    {
        if ($this->limits->maxSeconds === null) {
            return null;
        }

        return max(0.0, ($this->limits->maxSeconds * 1_000 - $this->elapsedMs()) / 1_000);
    }

    public function messages(): int
    {
        return $this->messages;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function elapsedMs(): int
    {
        return EpochMillis::now() - $this->startedAtMs;
    }

    public function usedMemory(): int
    {
        return ($this->memoryUsage)();
    }
}
