<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Fixtures;

/**
 * Trait for test handlers that need to track invocations and messages.
 *
 * Provides static state tracking for functional testing scenarios where
 * handler instances are created by Symfony Messenger container.
 *
 * IMPORTANT: Uses static state for test instrumentation.
 * - Must call reset() in test tearDown to prevent state leakage
 * - Not thread-safe (intended for single-threaded functional tests only)
 */
trait TrackableHandlerTrait
{
    private static int $invocationCount = 0;
    private static mixed $lastMessage = null;

    /**
     * Track invocation of this handler with the given message.
     */
    protected function track(mixed $message): void
    {
        ++self::$invocationCount;
        self::$lastMessage = $message;
    }

    /**
     * Get the number of times this handler was invoked.
     */
    public static function getInvocationCount(): int
    {
        return self::$invocationCount;
    }

    /**
     * Get the last message processed by this handler.
     */
    public static function getLastMessage(): mixed
    {
        return self::$lastMessage;
    }

    /**
     * Reset handler state (call in test tearDown).
     */
    public static function reset(): void
    {
        self::$invocationCount = 0;
        self::$lastMessage = null;
    }
}
