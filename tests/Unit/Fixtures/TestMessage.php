<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Fixtures;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\MessageName;
use Freyr\MessageBroker\Outbox\OutboxMessage;

/**
 * Test message for unit testing outbox serialization.
 *
 * Simple message with basic business properties.
 * No messageId property - MessageIdStampMiddleware generates it at dispatch time.
 */
#[MessageName('test.message.sent')]
final readonly class TestMessage implements OutboxMessage
{
    public function __construct(
        public Id $id,
        public string $name,
        public CarbonImmutable $timestamp,
    ) {}
}
