<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Handler;

use Freyr\MessageBroker\Tests\Fixtures\Consumer\OrderPlacedMessage;
use Freyr\MessageBroker\Tests\Fixtures\Consumer\UserPremiumUpgradedMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Test handler that collects received messages for verification
 */
final class TestMessageCollector
{
    /** @var array<object> */
    private array $receivedMessages = [];

    #[AsMessageHandler]
    public function handleOrderPlaced(OrderPlacedMessage $message): void
    {
        $this->receivedMessages[] = $message;
    }

    #[AsMessageHandler]
    public function handleUserPremiumUpgraded(UserPremiumUpgradedMessage $message): void
    {
        $this->receivedMessages[] = $message;
    }

    /**
     * @return array<object>
     */
    public function getReceivedMessages(): array
    {
        return $this->receivedMessages;
    }

    public function clear(): void
    {
        $this->receivedMessages = [];
    }

    public function hasReceived(string $messageClass): bool
    {
        foreach ($this->receivedMessages as $message) {
            if ($message instanceof $messageClass) {
                return true;
            }
        }
        return false;
    }

    public function countReceived(string $messageClass): int
    {
        $count = 0;
        foreach ($this->receivedMessages as $message) {
            if ($message instanceof $messageClass) {
                $count++;
            }
        }
        return $count;
    }
}
