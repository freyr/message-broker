<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Fixtures;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Outbox\EventBridge\OutboxMessage;
use Freyr\MessageBroker\Outbox\MessageName;

/**
 * Test message for verifying AMQP transport routing.
 *
 * This message is configured to be routed to AMQP transport instead of outbox.
 */
#[MessageName('test.amqp.sent')]
final readonly class AmqpTestMessage implements OutboxMessage
{
    public function __construct(
        public Id $eventId,
        public string $payload,
        public CarbonImmutable $sentAt,
    ) {
    }
}
