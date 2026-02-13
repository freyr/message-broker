<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Fixtures;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\OutboxMessage;
use Freyr\MessageBroker\Outbox\MessageName;

/**
 * Test event for functional testing outbox flow.
 *
 * Simple event with basic business properties.
 * No messageId property - MessageIdStampMiddleware generates it at dispatch time.
 */
#[MessageName('test.event.sent')]
final readonly class TestEvent implements OutboxMessage
{
    public function __construct(
        public Id $id,
        public string $name,
        public CarbonImmutable $timestamp,
    ) {}
}
