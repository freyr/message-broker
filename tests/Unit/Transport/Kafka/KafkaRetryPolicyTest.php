<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Transport\Kafka;

use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\RetryAction;
use Freyr\MessageBroker\Transport\Kafka\KafkaRetryPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KafkaRetryPolicyTest extends TestCase
{
    public function testRetriesBeforeTheCeilingThenDeadLetters(): void
    {
        $policy = new KafkaRetryPolicy(
            maxAttempts: 3,
            backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 100),
        );

        $first = $policy->decide(1, new RuntimeException('boom'));
        self::assertSame(RetryAction::Retry, $first->action);
        self::assertSame(100, $first->delayMs);

        $exhausted = $policy->decide(3, new RuntimeException('boom'));
        self::assertSame(RetryAction::DeadLetter, $exhausted->action);
    }
}
