<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Worker;

use Freyr\MessageBroker\Worker\WorkerLimitGuard;
use Freyr\MessageBroker\Worker\WorkerLimits;
use Freyr\MessageBroker\Worker\WorkerStopReason;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class WorkerLimitGuardTest extends TestCase
{
    private MockClock $clock;
    private int $memory = 0;
    private int $memoryReads = 0;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-06-15 12:00:00.000 UTC');
        Clock::set($this->clock);
        $this->memory = 0;
        $this->memoryReads = 0;
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testNoLimitNeverStopsAndReadsNoMemory(): void
    {
        $guard = $this->guard(new WorkerLimits());
        $guard->start();
        $guard->recordMessages(1_000_000);
        $guard->recordFailure();
        $this->clock->sleep(86_400);
        $this->memory = PHP_INT_MAX;

        self::assertNull($guard->stopReason());
        self::assertSame(0, $this->memoryReads);
        self::assertNull($guard->secondsLeft());
    }

    public function testTheMessageLimitStopsAtTheLimitAndNotBefore(): void
    {
        $guard = $this->guard(new WorkerLimits(maxMessages: 3));
        $guard->start();

        $guard->recordMessages(2);
        self::assertNull($guard->stopReason());

        $guard->recordMessages(1);
        self::assertSame(WorkerStopReason::MessageLimit, $guard->stopReason());
    }

    public function testTheMessageCountAddsUpAndASingleCallMayJumpOverTheLimit(): void
    {
        $guard = $this->guard(new WorkerLimits(maxMessages: 3));
        $guard->start();

        $guard->recordMessages(5);

        self::assertSame(WorkerStopReason::MessageLimit, $guard->stopReason());
    }

    public function testTheFailureLimitStopsAtTheLimitAndNotBefore(): void
    {
        $guard = $this->guard(new WorkerLimits(maxFailures: 2));
        $guard->start();

        $guard->recordFailure();
        self::assertNull($guard->stopReason());

        $guard->recordFailure();
        self::assertSame(WorkerStopReason::FailureLimit, $guard->stopReason());
    }

    public function testTheMemoryLimitStopsAtTheLimitAndNotBefore(): void
    {
        $guard = $this->guard(new WorkerLimits(maxMemoryBytes: 1_000));
        $guard->start();

        $this->memory = 999;
        self::assertNull($guard->stopReason());

        $this->memory = 1_000;
        self::assertSame(WorkerStopReason::MemoryLimit, $guard->stopReason());
    }

    public function testTheTimeLimitStopsAtTheLimitAndNotBefore(): void
    {
        $guard = $this->guard(new WorkerLimits(maxSeconds: 10));
        $guard->start();

        $this->clock->sleep(9.999);
        self::assertNull($guard->stopReason());

        $this->clock->sleep(0.001);
        self::assertSame(WorkerStopReason::TimeLimit, $guard->stopReason());
    }

    public function testWhenAllLimitsAreReachedTheMessageLimitIsReported(): void
    {
        $guard = $this->guard(new WorkerLimits(maxMessages: 1, maxSeconds: 1, maxMemoryBytes: 1, maxFailures: 1));
        $guard->start();
        $guard->recordMessages(1);
        $guard->recordFailure();
        $this->memory = 1;
        $this->clock->sleep(1);

        self::assertSame(WorkerStopReason::MessageLimit, $guard->stopReason());
    }

    public function testMemoryComesBeforeTime(): void
    {
        $guard = $this->guard(new WorkerLimits(maxSeconds: 1, maxMemoryBytes: 1));
        $guard->start();
        $this->memory = 1;
        $this->clock->sleep(1);

        self::assertSame(WorkerStopReason::MemoryLimit, $guard->stopReason());
    }

    public function testFailuresComeBeforeMemory(): void
    {
        $guard = $this->guard(new WorkerLimits(maxMemoryBytes: 1, maxFailures: 1));
        $guard->start();
        $this->memory = 1;
        $guard->recordFailure();

        self::assertSame(WorkerStopReason::FailureLimit, $guard->stopReason());
        self::assertSame(0, $this->memoryReads, 'a reached failure limit needs no memory read');
    }

    public function testMessagesComeBeforeFailures(): void
    {
        $guard = $this->guard(new WorkerLimits(maxMessages: 1, maxFailures: 1));
        $guard->start();
        $guard->recordMessages(1);
        $guard->recordFailure();

        self::assertSame(WorkerStopReason::MessageLimit, $guard->stopReason());
    }

    public function testStartResetsTheCountsAndTheTime(): void
    {
        $guard = $this->guard(new WorkerLimits(maxMessages: 1, maxSeconds: 5, maxFailures: 1));
        $guard->start();
        $guard->recordMessages(1);
        $guard->recordFailure();
        $this->clock->sleep(5);
        self::assertNotNull($guard->stopReason());

        $guard->start();

        self::assertNull($guard->stopReason());
        self::assertSame(5.0, $guard->secondsLeft());

        $guard->recordFailure();
        self::assertSame(WorkerStopReason::FailureLimit, $guard->stopReason(), 'the failure count restarted at 0');
    }

    public function testSecondsLeftCountsDownAndNeverGoesBelowZero(): void
    {
        $guard = $this->guard(new WorkerLimits(maxSeconds: 10));
        $guard->start();

        self::assertSame(10.0, $guard->secondsLeft());

        $this->clock->sleep(2.5);
        self::assertSame(7.5, $guard->secondsLeft());

        $this->clock->sleep(7.5);
        self::assertSame(0.0, $guard->secondsLeft());

        $this->clock->sleep(100);
        self::assertSame(0.0, $guard->secondsLeft());
    }

    public function testSecondsLeftIsNullWithoutATimeLimit(): void
    {
        $guard = $this->guard(new WorkerLimits(maxMessages: 1));
        $guard->start();

        self::assertNull($guard->secondsLeft());
    }

    public function testTheCountersTheElapsedTimeAndTheMemoryAreReadable(): void
    {
        $guard = $this->guard(new WorkerLimits());
        $guard->start();
        $guard->recordMessages(4);
        $guard->recordMessages(3);
        $guard->recordFailure();
        $this->clock->sleep(1.5);
        $this->memory = 4_096;

        self::assertSame(7, $guard->messages());
        self::assertSame(1, $guard->failures());
        self::assertSame(1_500, $guard->elapsedMs());
        self::assertSame(4_096, $guard->usedMemory());
    }

    public function testTheDefaultMemorySourceIsMemoryGetUsage(): void
    {
        $stops = new WorkerLimitGuard(new WorkerLimits(maxMemoryBytes: 1));
        $stops->start();
        self::assertSame(WorkerStopReason::MemoryLimit, $stops->stopReason());

        $runs = new WorkerLimitGuard(new WorkerLimits(maxMemoryBytes: PHP_INT_MAX));
        $runs->start();
        self::assertNull($runs->stopReason());
    }

    private function guard(WorkerLimits $limits): WorkerLimitGuard
    {
        return new WorkerLimitGuard($limits, function (): int {
            ++$this->memoryReads;

            return $this->memory;
        });
    }
}
