<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\DefaultDeliveryRetryPolicy;
use Freyr\MessageBroker\Webhook\DeliveryDecision;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class DefaultDeliveryRetryPolicyTest extends TestCase
{
    private int $now;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-06-15 12:00:00.250 UTC'));
        $this->now = EpochMillis::now();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testTheDefaultScheduleHasEightAttemptsOfAboutTwentySevenAndAHalfHours(): void
    {
        self::assertSame(
            [5_000, 300_000, 1_800_000, 7_200_000, 18_000_000, 36_000_000, 36_000_000],
            DefaultDeliveryRetryPolicy::SCHEDULE_MS,
        );
        self::assertSame(99_305_000, array_sum(DefaultDeliveryRetryPolicy::SCHEDULE_MS), '27 h 35 min 5 s');
    }

    /** @return iterable<string, array{int, int}> */
    public static function scheduleSteps(): iterable
    {
        yield 'attempt 1' => [1, 5_000];
        yield 'attempt 2' => [2, 300_000];
        yield 'attempt 3' => [3, 1_800_000];
        yield 'attempt 4' => [4, 7_200_000];
        yield 'attempt 5' => [5, 18_000_000];
        yield 'attempt 6' => [6, 36_000_000];
        yield 'attempt 7' => [7, 36_000_000];
    }

    #[DataProvider('scheduleSteps')]
    public function testAFailedAttemptBeforeTheLimitRetriesAfterTheDelayOfTheSchedule(int $attempt, int $delayMs): void
    {
        $decision = (new DefaultDeliveryRetryPolicy())->decide($attempt, DeliveryResult::failed(10, '500'), self::record());

        self::assertFalse($decision->isDeadLetter());
        self::assertSame($this->now + $delayMs, $decision->retryAt);
    }

    public function testTheEighthFailedAttemptIsADeadLetter(): void
    {
        $decision = (new DefaultDeliveryRetryPolicy())->decide(8, DeliveryResult::failed(10, '500'), self::record());

        self::assertTrue($decision->isDeadLetter());
        self::assertNull($decision->retryAt);
    }

    public function testARefusedResultIsADeadLetterAtTheFirstAttempt(): void
    {
        $decision = (new DefaultDeliveryRetryPolicy())->decide(1, DeliveryResult::refused('gone', 10, '410'), self::record());

        self::assertTrue($decision->isDeadLetter());
    }

    public function testTheDelaysAreConfigurationAndGiveTheNumberOfAttempts(): void
    {
        $policy = new DefaultDeliveryRetryPolicy([1_000, 2_000]);

        self::assertSame($this->now + 2_000, $policy->decide(2, DeliveryResult::failed(1, 'x'), self::record())->retryAt);
        self::assertTrue($policy->decide(3, DeliveryResult::failed(1, 'x'), self::record())->isDeadLetter(), 'two delays: three attempts');
    }

    public function testNoDelayMeansOneAttempt(): void
    {
        self::assertTrue((new DefaultDeliveryRetryPolicy([]))->decide(1, DeliveryResult::failed(1, 'x'), self::record())->isDeadLetter());
    }

    public function testALaterRetryTimeOfTheReceiverReplacesTheBackoff(): void
    {
        $result = DeliveryResult::failed(1, '429', $this->now + 60_000);

        $decision = (new DefaultDeliveryRetryPolicy())->decide(1, $result, self::record());

        self::assertSame($this->now + 60_000, $decision->retryAt);
    }

    public function testAnEarlierRetryTimeOfTheReceiverKeepsTheBackoff(): void
    {
        $result = DeliveryResult::failed(1, '429', $this->now + 4_999);

        $decision = (new DefaultDeliveryRetryPolicy())->decide(1, $result, self::record());

        self::assertSame($this->now + 5_000, $decision->retryAt);
    }

    public function testTheRetryTimeOfTheReceiverIsCutToTheConfiguredMaximum(): void
    {
        $policy = new DefaultDeliveryRetryPolicy([5_000], maxReceiverDelayMs: 120_000);

        $cut = $policy->decide(1, DeliveryResult::failed(1, '503', $this->now + 120_001), self::record());
        $atLimit = $policy->decide(1, DeliveryResult::failed(1, '503', $this->now + 120_000), self::record());

        self::assertSame($this->now + 120_000, $cut->retryAt);
        self::assertSame($this->now + 120_000, $atLimit->retryAt);
    }

    public function testAMaximumBelowTheBackoffKeepsTheBackoff(): void
    {
        $policy = new DefaultDeliveryRetryPolicy([5_000], maxReceiverDelayMs: 1_000);

        $decision = $policy->decide(1, DeliveryResult::failed(1, '503', $this->now + 60_000), self::record());

        self::assertSame($this->now + 5_000, $decision->retryAt);
    }

    public function testTheDefaultMaximumOfTheReceiverIsOneDay(): void
    {
        $decision = (new DefaultDeliveryRetryPolicy())->decide(1, DeliveryResult::failed(1, '503', $this->now + 90_000_000), self::record());

        self::assertSame($this->now + 86_400_000, $decision->retryAt);
    }

    public function testTheLongestDelayIsTheLongestStepOrTheMaximumOfTheReceiver(): void
    {
        self::assertSame(86_400_000, (new DefaultDeliveryRetryPolicy())->longestDelayMs());
        self::assertSame(36_000_000, (new DefaultDeliveryRetryPolicy(maxReceiverDelayMs: 1_000))->longestDelayMs());
        self::assertSame(9_000, (new DefaultDeliveryRetryPolicy([1_000, 9_000, 2_000], 8_999))->longestDelayMs());
        self::assertSame(500, (new DefaultDeliveryRetryPolicy([], 500))->longestDelayMs());
    }

    public function testADecisionKeepsItsTime(): void
    {
        self::assertSame(123, DeliveryDecision::retryAt(123)->retryAt);
        self::assertFalse(DeliveryDecision::retryAt(123)->isDeadLetter());
        self::assertTrue(DeliveryDecision::deadLetter()->isDeadLetter());
        self::assertNull(DeliveryDecision::deadLetter()->retryAt);
    }

    private static function record(): DeliveryRecord
    {
        return new DeliveryRecord('01900000-0000-7000-8000-000000000001', '01900000-0000-7000-8000-0000000000aa', 'crm', 'order.placed', '{}', [], 1);
    }
}
