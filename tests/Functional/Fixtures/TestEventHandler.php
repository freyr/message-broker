<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Test handler for TestEvent - tracks invocations for functional testing.
 */
#[AsMessageHandler]
final class TestEventHandler
{
    private static int $invocationCount = 0;
    private static ?TestEvent $lastMessage = null;

    public function __invoke(TestEvent $message): void
    {
        self::$invocationCount++;
        self::$lastMessage = $message;
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
    }
}
