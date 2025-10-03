<?php

declare(strict_types=1);

namespace Freyr\Messenger\Tests\Fixtures\Publisher;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\Messenger\Outbox\MessageName;
use Freyr\Messenger\Outbox\Routing\AmqpRoutingKey;

/**
 * Test Domain Event with Custom Routing Key - Publisher Side (Outbox).
 *
 * Demonstrates wildcard routing key override.
 */
#[MessageName('user.premium.upgraded')]
#[AmqpRoutingKey('user.*.upgraded')]  // Wildcard routing
final readonly class UserPremiumUpgradedEvent
{
    public function __construct(
        public Id $messageId,
        public Id $userId,
        public string $plan,
        public CarbonImmutable $upgradedAt,
    ) {
    }
}
