<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Test handler for OrderPlaced - tracks invocations for functional testing.
 */
#[AsMessageHandler]
final class OrderPlacedHandler
{
    use TrackableHandlerTrait;

    public function __invoke(OrderPlaced $message): void
    {
        $this->track($message);
    }
}
