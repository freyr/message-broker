<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Transport;

use Doctrine\DBAL\Connection;

/**
 * @internal MySQL/MariaDB platform strategy for OrderedOutboxTransport.
 * Uses DBAL insert() + lastInsertId(). No extra schema or query filtering.
 */
final readonly class MySqlOutboxPlatformStrategy implements OutboxPlatformStrategy
{
    public function insertAndReturnId(
        Connection $connection,
        string $tableName,
        array $values,
        array $types,
    ): string {
        $connection->insert($tableName, $values, $types);

        return (string) $connection->lastInsertId();
    }

    public function buildHeadOfLineFilter(): string
    {
        return '';
    }

    public function afterTableCreated(Connection $connection, string $tableName): void
    {
        // MySQL: no extra schema needed
    }
}
