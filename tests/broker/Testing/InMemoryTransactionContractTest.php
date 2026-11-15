<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Testing;

use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Storage\Transaction;
use Freyr\MessageBroker\Testing\InMemoryDeduplicationStore;
use Freyr\MessageBroker\Testing\InMemoryOutboxStore;
use Freyr\MessageBroker\Testing\InMemoryTransaction;
use Freyr\MessageBroker\Testing\TransactionContractTestCase;

/** The in-memory transaction keeps the contract of the port, with the in-memory stores that join it. */
final class InMemoryTransactionContractTest extends TransactionContractTestCase
{
    private ?InMemoryTransaction $transaction = null;

    protected function createTransaction(): Transaction
    {
        return $this->shared();
    }

    protected function createDeduplicationStore(): DeduplicationStore
    {
        return new InMemoryDeduplicationStore($this->shared());
    }

    protected function createOutboxStore(): OutboxStore
    {
        return new InMemoryOutboxStore($this->shared());
    }

    private function shared(): InMemoryTransaction
    {
        return $this->transaction ??= new InMemoryTransaction();
    }
}
