<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage;

/**
 * Creates the tables of the library: outbox_messages, message_deduplication
 * and dead_letters. One operation covers the tables and the first storage
 * units of the outbox (PostgreSQL: daily partitions), because a database may
 * need to define those units inside the CREATE TABLE statement.
 * {@see PostgreSqlSchemaInstaller} is the PostgreSQL implementation.
 */
interface SchemaInstaller
{
    /**
     * The complete DDL for a new database, with the storage units for today
     * to today + $daysAhead days. It need not be idempotent.
     *
     * @return list<string>
     */
    public function sql(int $daysAhead): array;

    /**
     * Create what is missing, with the storage units for today to
     * today + $daysAhead days. Safe to repeat. Must run outside a transaction.
     */
    public function install(int $daysAhead): void;
}
