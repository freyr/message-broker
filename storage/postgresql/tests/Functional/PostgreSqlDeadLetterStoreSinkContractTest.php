<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterSink;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Testing\DeadLetterSinkContractTestCase;

/** The PostgreSQL dead-letter store keeps the contract of the DeadLetterSink port. */
final class PostgreSqlDeadLetterStoreSinkContractTest extends DeadLetterSinkContractTestCase
{
    use PostgreSqlTestSupport;

    private PostgreSqlDeadLetterStore $store;

    protected function createSink(): DeadLetterSink
    {
        return $this->store = new PostgreSqlDeadLetterStore(self::$pdo);
    }

    protected function storedDeadLetters(): array
    {
        return $this->store->list(new DeadLetterFilter());
    }
}
