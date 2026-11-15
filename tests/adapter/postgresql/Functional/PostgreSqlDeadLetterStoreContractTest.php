<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Adapter\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Testing\DeadLetterStoreContractTestCase;

/** The PostgreSQL dead-letter store keeps the contract of the DeadLetterStore port. */
final class PostgreSqlDeadLetterStoreContractTest extends DeadLetterStoreContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createStore(): DeadLetterStore
    {
        return new PostgreSqlDeadLetterStore(self::$pdo);
    }
}
