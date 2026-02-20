<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Contracts\MessageName;
use Freyr\MessageBroker\Contracts\OutboxMessage;

/**
 * Test fixture: an outbox-side event.
 *
 * Implements OutboxMessage + #[MessageName] with value-object properties
 * (Id, string, CarbonImmutable) to exercise the full normalizer chain.
 */
#[MessageName('test.event.sent')]
final readonly class TestOutboxEvent implements OutboxMessage
{
    public function __construct(
        public Id $eventId,
        public string $payload,
        public CarbonImmutable $occurredAt,
    ) {}

    public static function random(string $payload = 'Test'): self
    {
        return new self(eventId: Id::new(), payload: $payload, occurredAt: CarbonImmutable::now());
    }
}
