<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Retry;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Retry\Backoff;
use Freyr\MessageBroker\Retry\ExponentialRetryPolicy;
use Freyr\MessageBroker\Retry\RetryAction;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;
use Throwable;

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

    public function testDeadLetterOnMatchDeadLettersAtAttemptOne(): void
    {
        $policy = new ExponentialRetryPolicy(deadLetterOn: [LogicException::class]);

        self::assertSame(RetryAction::DeadLetter, $policy->decide(1, new LogicException('bad'), self::message())->action);
    }

    public function testDeadLetterOnMatchesASubclass(): void
    {
        $policy = new ExponentialRetryPolicy(deadLetterOn: [LogicException::class]);

        self::assertSame(
            RetryAction::DeadLetter,
            $policy->decide(1, new InvalidArgumentException('bad'), self::message())->action,
        );
    }

    public function testDeadLetterOnMatchesAnInterface(): void
    {
        $policy = new ExponentialRetryPolicy(deadLetterOn: [MarkerThrowable::class]);

        self::assertSame(
            RetryAction::DeadLetter,
            $policy->decide(1, new MarkedException('bad'), self::message())->action,
        );
        self::assertSame(RetryAction::Retry, $policy->decide(1, new RuntimeException('bad'), self::message())->action);
    }

    public function testDiscardOnMatchDiscards(): void
    {
        $policy = new ExponentialRetryPolicy(discardOn: [LogicException::class]);

        self::assertSame(RetryAction::Discard, $policy->decide(1, new LogicException('bad'), self::message())->action);
    }

    public function testDiscardOnMatchesASubclassAndAnInterface(): void
    {
        $subclass = new ExponentialRetryPolicy(discardOn: [LogicException::class]);
        $interface = new ExponentialRetryPolicy(discardOn: [MarkerThrowable::class]);

        self::assertSame(
            RetryAction::Discard,
            $subclass->decide(1, new InvalidArgumentException('bad'), self::message())->action,
        );
        self::assertSame(
            RetryAction::Discard,
            $interface->decide(1, new MarkedException('bad'), self::message())->action,
        );
    }

    public function testAnErrorInTheTwoListsDeadLetters(): void
    {
        $policy = new ExponentialRetryPolicy(
            deadLetterOn: [LogicException::class],
            discardOn: [LogicException::class],
        );

        self::assertSame(RetryAction::DeadLetter, $policy->decide(1, new LogicException('bad'), self::message())->action);
    }

    public function testDiscardOnMatchAtMaxAttemptsDiscards(): void
    {
        $policy = new ExponentialRetryPolicy(maxAttempts: 3, discardOn: [LogicException::class]);

        self::assertSame(RetryAction::Discard, $policy->decide(3, new LogicException('bad'), self::message())->action);
    }

    public function testAnErrorInNoListKeepsTheBackoffAndTheAttemptLimit(): void
    {
        $policy = new ExponentialRetryPolicy(
            maxAttempts: 3,
            backoff: Backoff::exponential(initialDelayMs: 100, maxDelayMs: 10_000, multiplier: 2.0),
            deadLetterOn: [LogicException::class],
            discardOn: [MarkerThrowable::class],
        );

        $retry = $policy->decide(2, new RuntimeException('boom'), self::message());

        self::assertSame(RetryAction::Retry, $retry->action);
        self::assertSame(200, $retry->delayMs);
        self::assertSame(RetryAction::DeadLetter, $policy->decide(3, new RuntimeException('boom'), self::message())->action);
    }

    public function testAnEntryThatIsNotAThrowableClassInDeadLetterOnThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialRetryPolicy(deadLetterOn: [Stringable::class]);
    }

    public function testAnEntryThatIsNotAThrowableClassInDiscardOnThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialRetryPolicy(discardOn: ['No\\Such\\Class']);
    }

    public function testEmptyListsKeepTheCurrentBehavior(): void
    {
        $policy = new ExponentialRetryPolicy(maxAttempts: 2, deadLetterOn: [], discardOn: []);

        self::assertSame(RetryAction::Retry, $policy->decide(1, new RuntimeException('boom'), self::message())->action);
        self::assertSame(RetryAction::DeadLetter, $policy->decide(2, new RuntimeException('boom'), self::message())->action);
    }

    private static function message(): IncomingMessage
    {
        return new IncomingMessage('m-1', 'order.placed', 1, []);
    }
}

interface MarkerThrowable extends Throwable {}

final class MarkedException extends RuntimeException implements MarkerThrowable {}
