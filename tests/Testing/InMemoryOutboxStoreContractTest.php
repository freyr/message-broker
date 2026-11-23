<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use Freyr\MessageBroker\Testing\OutboxStoreContractTestCase;

/** The in-memory outbox store keeps the contract of the port: the proof that the fake is honest. */
final class InMemoryOutboxStoreContractTest extends OutboxStoreContractTestCase
{
    protected function createStore(): OutboxStore
    {
        return new InMemoryOutboxStore();
    }

    protected function createSecondStore(): OutboxStore
    {
        return $this->store(); // one process: the open claim of the same object is the rival
    }
}
