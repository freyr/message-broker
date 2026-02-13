<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Fixtures;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Contracts\MessageName;
use Freyr\MessageBroker\Contracts\OutboxMessage;

/**
 * Sample outbox message for testing transport serialisation.
 */
#[MessageName('test.sample.sent')]
final readonly class SampleOutboxMessage implements OutboxMessage
{
    public function __construct(
        public Id $eventId,
        public string $payload,
        public CarbonImmutable $sentAt,
    ) {}
}
