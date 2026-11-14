<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport\Amqp;

use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\RetryAction;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AmqpRetryPolicyTest extends TestCase
{
    public function testRetriesWithBackoffDelayWhileAttemptsRemain(): void
    {
        $policy = new AmqpRetryPolicy(
            maxAttempts: 3,
            backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 10_000, multiplier: 2.0),
        );

        $first = $policy->decide(1, new RuntimeException('boom'));
        $second = $policy->decide(2, new RuntimeException('boom'));

        self::assertSame(RetryAction::Retry, $first->action);
        self::assertSame(100, $first->delayMs);
        self::assertSame(RetryAction::Retry, $second->action);
        self::assertSame(200, $second->delayMs);
    }

    public function testDeadLettersWhenAttemptsExhausted(): void
    {
        $policy = new AmqpRetryPolicy(
            maxAttempts: 3,
            backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 10_000),
        );

        $decision = $policy->decide(3, new RuntimeException('boom'));

        self::assertSame(RetryAction::DeadLetter, $decision->action);
    }
}
