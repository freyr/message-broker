<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures\Consumer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;

/**
 * Test Message DTO - Consumer Side (Inbox).
 *
 * Message name: 'sla.calculation.started' (same as publisher event)
 * Class name: Different from publisher
 */
final readonly class SlaCalculationStartedMessage
{
    public function __construct(
        public Id $slaId,
        public Id $ticketId,
        public CarbonImmutable $startedAt,
    ) {
    }
}
