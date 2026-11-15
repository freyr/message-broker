<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Adapter\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\Testing\DeduplicationStoreContractTestCase;

/** The PostgreSQL deduplication store keeps the contract of the DeduplicationStore port. */
final class PostgreSqlDeduplicationStoreContractTest extends DeduplicationStoreContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createStore(): DeduplicationStore
    {
        return new PostgreSqlDeduplicationStore(self::$pdo);
    }
}
