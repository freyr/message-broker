<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterSink;
use Freyr\MessageBroker\Testing\DeadLetterSinkContractTestCase;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;

/** The in-memory dead-letter store keeps the contract of the DeadLetterSink port. */
final class InMemoryDeadLetterStoreSinkContractTest extends DeadLetterSinkContractTestCase
{
    private InMemoryDeadLetterStore $store;

    protected function createSink(): DeadLetterSink
    {
        return $this->store = new InMemoryDeadLetterStore();
    }

    protected function storedDeadLetters(): array
    {
        return $this->store->list(new DeadLetterFilter());
    }
}
