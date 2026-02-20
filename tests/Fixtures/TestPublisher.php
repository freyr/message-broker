<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Contracts\OutboxPublisherInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * Test fixture: stub OutboxPublisherInterface.
 *
 * Used by OutboxPublisherPassTest where a concrete class (not anonymous) is needed.
 */
final readonly class TestPublisher implements OutboxPublisherInterface
{
    public function publish(Envelope $envelope): void {}
}
