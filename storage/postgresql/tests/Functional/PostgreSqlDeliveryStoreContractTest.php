<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeadLetterStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryRetention;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryStore;
use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
use Freyr\MessageBroker\Testing\DeliveryStoreContractTestCase;
use Freyr\MessageBroker\Webhook\DeliveryStore;

/** The PostgreSQL delivery store keeps the contract of the DeliveryStore port. */
final class PostgreSqlDeliveryStoreContractTest extends DeliveryStoreContractTestCase
{
    use PostgreSqlTestSupport;

    protected function createStore(): DeliveryStore
    {
        // The contract inserts rows with a created_at up to 60 s before now: in the
        // first minute of a UTC day they belong to the day before. Day before,
        // today and tomorrow.
        (new PostgreSqlDeliveryRetention(self::$pdo))->ensureCapacity(2, (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-1 day'));

        return new PostgreSqlDeliveryStore(self::$pdo);
    }

    protected function deadLetterStore(): DeadLetterStore
    {
        return new PostgreSqlDeadLetterStore(self::$pdo);
    }
}
