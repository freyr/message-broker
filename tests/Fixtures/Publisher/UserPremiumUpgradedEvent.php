<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures\Publisher;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\EventBridge\OutboxMessage;
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\Routing\AmqpRoutingKey;

/**
 * Test Domain Event with Custom Routing Key - Publisher Side (Outbox).
 *
 * Demonstrates wildcard routing key override.
 */
#[MessageName('user.premium.upgraded')]
#[AmqpRoutingKey('user.*.upgraded')] // Wildcard routing
final readonly class UserPremiumUpgradedEvent implements OutboxMessage
{
    public function __construct(
        public Id $messageId,
        public Id $userId,
        public string $plan,
        public CarbonImmutable $upgradedAt,
    ) {
    }
}
