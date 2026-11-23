<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Consumer\DeduplicationStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeduplicationStore;
use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
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
