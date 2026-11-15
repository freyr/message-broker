<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Adapter\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Testing\OutboxStoreContractTestCase;

/** The PostgreSQL outbox store keeps the contract of the OutboxStore port. */
final class PostgreSqlOutboxStoreContractTest extends OutboxStoreContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createStore(): OutboxStore
    {
        return new PostgreSqlOutboxStore(self::$pdo);
    }

    protected function createSecondStore(): OutboxStore
    {
        return new PostgreSqlOutboxStore(self::newConnection());
    }
}
