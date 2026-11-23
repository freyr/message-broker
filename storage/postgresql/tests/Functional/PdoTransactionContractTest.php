<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Storage\PostgreSql\PdoTransaction;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Storage\Transaction;
use Freyr\MessageBroker\Testing\TransactionContractTestCase;

/** PdoTransaction keeps the contract of the Transaction port on one real connection. */
final class PdoTransactionContractTest extends TransactionContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createTransaction(): Transaction
    {
        return new PdoTransaction(self::$pdo);
    }

    protected function createDeduplicationStore(): DeduplicationStore
    {
        return new PostgreSqlDeduplicationStore(self::$pdo);
    }

    protected function createOutboxStore(): OutboxStore
    {
        return new PostgreSqlOutboxStore(self::$pdo);
    }

    protected function createDeadLetterStore(): DeadLetterStore
    {
        return new PostgreSqlDeadLetterStore(self::$pdo);
    }
}
