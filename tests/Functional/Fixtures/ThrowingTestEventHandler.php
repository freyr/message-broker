<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Test handler that can throw exceptions on demand.
 *
 * Used for testing transaction rollback scenarios.
 * Tracks invocations and allows configuring exceptions for specific invocations.
 */
#[AsMessageHandler]
final class ThrowingTestEventHandler
{
    private static int $invocationCount = 0;
    private static ?TestEvent $lastMessage = null;
    private static ?\Throwable $exceptionToThrow = null;

    public function __invoke(TestEvent $message): void
    {
        self::$invocationCount++;
        self::$lastMessage = $message;

        if (self::$exceptionToThrow !== null) {
            $exception = self::$exceptionToThrow;
            self::$exceptionToThrow = null; // Reset after throwing
            throw $exception;
        }
    }

    /**
     * Configure handler to throw exception on next invocation.
     */
    public static function throwOnNextInvocation(\Throwable $exception): void
    {
        self::$exceptionToThrow = $exception;
    }

    public static function getInvocationCount(): int
    {
        return self::$invocationCount;
    }

    public static function getLastMessage(): ?TestEvent
    {
        return self::$lastMessage;
    }

    public static function reset(): void
    {
        self::$invocationCount = 0;
        self::$lastMessage = null;
        self::$exceptionToThrow = null;
    }
}
