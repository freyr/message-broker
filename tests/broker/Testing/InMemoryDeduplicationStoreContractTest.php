<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\Testing\DeduplicationStoreContractTestCase;
use Freyr\MessageBroker\Testing\InMemoryDeduplicationStore;

/** The in-memory deduplication store keeps the contract of the port. */
final class InMemoryDeduplicationStoreContractTest extends DeduplicationStoreContractTestCase
{
    protected function createStore(): DeduplicationStore
    {
        return new InMemoryDeduplicationStore();
    }
}
