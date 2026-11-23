<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Storage\PostgreSql\PublishedRows;
use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Testing\OutboxStoreContractTestCase;

/** The PostgreSQL outbox store that deletes published rows keeps the contract of the OutboxStore port. */
final class PostgreSqlDeletingOutboxStoreContractTest extends OutboxStoreContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createStore(): OutboxStore
    {
        // Day before, today and tomorrow, as in PostgreSqlOutboxStoreContractTest.
        (new PostgreSqlOutboxRetention(self::$pdo))->ensureCapacity(2, (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-1 day'));

        return new PostgreSqlOutboxStore(self::$pdo, PublishedRows::Delete);
    }

    protected function createSecondStore(): OutboxStore
    {
        return new PostgreSqlOutboxStore(self::newConnection(), PublishedRows::Delete);
    }
}
