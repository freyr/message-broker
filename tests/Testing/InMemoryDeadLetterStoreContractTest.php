<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Testing\DeadLetterStoreContractTestCase;
use Freyr\MessageBroker\Testing\InMemoryDeadLetterStore;

/** The in-memory dead-letter store keeps the contract of the port. */
final class InMemoryDeadLetterStoreContractTest extends DeadLetterStoreContractTestCase
{
    protected function createStore(): DeadLetterStore
    {
        return new InMemoryDeadLetterStore();
    }
}
