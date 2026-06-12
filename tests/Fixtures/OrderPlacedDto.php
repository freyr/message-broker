<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

/**
 * Consumer-side DTO owned by the consuming service — mirrors the payload
 * schema, does NOT extend Message (envelope data rides on IncomingMessage).
 */
final readonly class OrderPlacedDto
{
    public function __construct(
        public string $orderId,
        public int $totalCents,
    ) {}
}
