<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Fixtures;

use Freyr\MessageBroker\Contracts\OutboxPublisherInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * @internal test double implementing OutboxPublisherInterface
 */
final class TestPublisher implements OutboxPublisherInterface
{
    public function publish(Envelope $envelope): void {}
}
