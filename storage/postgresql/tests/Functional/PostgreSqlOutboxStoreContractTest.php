<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Testing\OutboxStoreContractTestCase;

/** The PostgreSQL outbox store keeps the contract of the OutboxStore port. */
final class PostgreSqlOutboxStoreContractTest extends OutboxStoreContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createStore(): OutboxStore
    {
        // The contract inserts rows with a created_at up to 8 s before now: in the
        // first seconds of a UTC day they belong to the day before. Day before,
        // today and tomorrow.
        (new PostgreSqlOutboxRetention(self::$pdo))->ensureCapacity(2, (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-1 day'));

        return new PostgreSqlOutboxStore(self::$pdo);
    }

    public function testThePartitionOfTheDayBeforeExistsAfterCreateStore(): void
    {
        $this->store();

        $yesterday = 'outbox_messages_p'.(new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-1 day')->format('Ymd');
        self::assertSame(1, self::fetchInt(
            "SELECT COUNT(*) FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'outbox_messages'::regclass AND c.relname = '{$yesterday}'",
        ));
    }

    protected function createSecondStore(): OutboxStore
    {
        return new PostgreSqlOutboxStore(self::newConnection());
    }
}
