<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Test handler for TestEvent - tracks invocations for functional testing.
 */
#[AsMessageHandler]
final class TestEventHandler
{
    use TrackableHandlerTrait;

    public function __invoke(TestEvent $message): void
    {
        $this->track($message);
    }
}
