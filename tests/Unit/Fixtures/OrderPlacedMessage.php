<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Fixtures;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;

/**
 * Consumer message for OrderPlaced event.
 *
 * This represents the typed message on the consuming end (inbox).
 * Contains only business data (no messageId - it's transport metadata).
 */
final readonly class OrderPlacedMessage
{
    public function __construct(
        public Id $id,
        public string $name,
        public CarbonImmutable $timestamp,
    ) {}
}
