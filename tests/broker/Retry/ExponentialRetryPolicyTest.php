<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Retry;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Retry\RetryAction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExponentialRetryPolicyTest extends TestCase
{
    public function testRetriesWithBackoffDelayWhileAttemptsRemain(): void
    {
        $policy = new ExponentialRetryPolicy(
            maxAttempts: 3,
            backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 10_000, multiplier: 2.0),
        );

        $first = $policy->decide(1, new RuntimeException('boom'), self::message());
        $second = $policy->decide(2, new RuntimeException('boom'), self::message());

        self::assertSame(RetryAction::Retry, $first->action);
        self::assertSame(100, $first->delayMs);
        self::assertSame(RetryAction::Retry, $second->action);
        self::assertSame(200, $second->delayMs);
    }

    public function testDefaultPolicyRetriesFourTimesThenDeadLetters(): void
    {
        $policy = new ExponentialRetryPolicy();

        self::assertSame(RetryAction::Retry, $policy->decide(4, new RuntimeException('boom'), self::message())->action);
        self::assertSame(RetryAction::DeadLetter, $policy->decide(5, new RuntimeException('boom'), self::message())->action);
    }

    public function testDefaultBackoffStartsAtOneSecondAndCapsAtFiveMinutes(): void
    {
        $policy = new ExponentialRetryPolicy(maxAttempts: 20);

        self::assertSame(1_000, $policy->decide(1, new RuntimeException('boom'), self::message())->delayMs);
        self::assertSame(4_000, $policy->decide(2, new RuntimeException('boom'), self::message())->delayMs);
        self::assertSame(300_000, $policy->decide(10, new RuntimeException('boom'), self::message())->delayMs);
    }

    public function testDeadLettersWhenAttemptsExhausted(): void
    {
        $policy = new ExponentialRetryPolicy(
            maxAttempts: 3,
            backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 10_000),
        );

        $decision = $policy->decide(3, new RuntimeException('boom'), self::message());

        self::assertSame(RetryAction::DeadLetter, $decision->action);
    }

    private static function message(): IncomingMessage
    {
        return new IncomingMessage('m-1', 'order.placed', 1, []);
    }
}
