<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Transport;

use Doctrine\DBAL\Connection;

/**
 * @internal Platform-specific behavior for OrderedOutboxTransport.
 * Auto-selected by OrderedOutboxTransportFactory based on DBAL platform.
 * Not intended for implementation by end users.
 */
interface OutboxPlatformStrategy
{
    /**
     * Insert outbox row and return its generated ID.
     *
     * MySQL: DBAL insert() + lastInsertId()
     * PostgreSQL: INSERT ... RETURNING id
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $types
     */
    public function insertAndReturnId(
        Connection $connection,
        string $tableName,
        array $values,
        array $types,
    ): string;

    /**
     * Extra WHERE clause fragment for the head-of-line query.
     * Returns raw SQL string including leading AND, or empty string.
     *
     * NOTE: This returns a parameter-free SQL fragment. If a future platform
     * needs bind parameters, the signature will need to change.
     */
    public function buildHeadOfLineFilter(): string;

    /**
     * Apply platform-specific schema extensions after DBAL creates the base table.
     * MUST be idempotent — setup() can be called multiple times.
     *
     * MySQL: no-op
     * PostgreSQL: adds transaction_id xid8 column (with column-existence guard)
     */
    public function afterTableCreated(Connection $connection, string $tableName): void;
}
