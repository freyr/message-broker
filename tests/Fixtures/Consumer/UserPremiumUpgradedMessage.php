<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures\Consumer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;

/**
 * Test Message DTO - Consumer Side (Inbox).
 *
 * Message name: 'user.premium.upgraded' (same as publisher event)
 * Class name: Different from publisher
 */
final readonly class UserPremiumUpgradedMessage
{
    public function __construct(
        public Id $userId,
        public string $plan,
        public CarbonImmutable $upgradedAt,
    ) {
    }
}
