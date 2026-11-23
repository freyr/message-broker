<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Retry;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Retry\RetryDecision;
use Freyr\MessageBroker\Retry\RetryPolicy;
use Freyr\MessageBroker\Retry\RetryPolicyMap;
use Freyr\MessageBroker\Tests\Fixtures\RecordingPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class RetryPolicyMapTest extends TestCase
{
    public function testAKnownNameUsesItsPolicy(): void
    {
        $map = new RetryPolicyMap(self::fixed(RetryDecision::deadLetter()), [
            'order.placed' => self::fixed(RetryDecision::retryIn(700)),
        ]);

        $decision = $map->decide(1, new RuntimeException('boom'), self::message('order.placed'));

        self::assertSame(700, $decision->delayMs);
    }

    public function testAnUnknownNameUsesTheDefault(): void
    {
        $map = new RetryPolicyMap(self::fixed(RetryDecision::discard()), [
            'order.placed' => self::fixed(RetryDecision::retryIn(700)),
        ]);

        $decision = $map->decide(1, new RuntimeException('boom'), self::message('order.shipped'));

        self::assertEquals(RetryDecision::discard(), $decision);
    }

    public function testTheMatchIsExact(): void
    {
        $map = new RetryPolicyMap(self::fixed(RetryDecision::discard()), [
            'order.placed' => self::fixed(RetryDecision::retryIn(700)),
        ]);

        self::assertEquals(
            RetryDecision::discard(),
            $map->decide(1, new RuntimeException('boom'), self::message('order.placed.v2')),
        );
        self::assertEquals(
            RetryDecision::discard(),
            $map->decide(1, new RuntimeException('boom'), self::message('order.*')),
        );
    }

    public function testTheDelegateGetsTheSameAttemptErrorAndMessage(): void
    {
        $seen = new RecordingPolicy();
        $map = new RetryPolicyMap(self::fixed(RetryDecision::deadLetter()), [
            'order.placed' => $seen,
        ]);
        $error = new RuntimeException('boom');
        $message = self::message('order.placed');

        $map->decide(3, $error, $message);

        self::assertSame(3, $seen->attempt);
        self::assertSame($error, $seen->error);
        self::assertSame($message, $seen->message);
    }

    public function testTheDefaultGetsTheSameAttemptErrorAndMessage(): void
    {
        $seen = new RecordingPolicy();
        $map = new RetryPolicyMap($seen);
        $error = new RuntimeException('boom');
        $message = self::message('order.placed');

        $map->decide(2, $error, $message);

        self::assertSame(2, $seen->attempt);
        self::assertSame($error, $seen->error);
        self::assertSame($message, $seen->message);
    }

    public function testAnEmptyKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RetryPolicyMap(self::fixed(RetryDecision::deadLetter()), [
            '' => self::fixed(RetryDecision::deadLetter()),
        ]);
    }

    public function testAValueThatIsNotARetryPolicyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new RetryPolicyMap(self::fixed(RetryDecision::deadLetter()), [
            'order.placed' => 'not a policy',
        ]);
    }

    private static function message(string $name): IncomingMessage
    {
        return new IncomingMessage('m-1', $name, 1, []);
    }

    private static function fixed(RetryDecision $decision): RetryPolicy
    {
        return new class($decision) implements RetryPolicy {
            public function __construct(
                private readonly RetryDecision $decision,
            ) {}

            public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
            {
                return $this->decision;
            }
        };
    }
}
