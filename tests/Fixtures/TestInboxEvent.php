<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;

/**
 * Test fixture: an inbox-side event.
 *
 * Plain readonly class without OutboxMessage or #[MessageName] — represents
 * a message received from an external producer via AMQP.
 */
final readonly class TestInboxEvent
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
