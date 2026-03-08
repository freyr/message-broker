<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\Transport;

use Doctrine\DBAL\Connection;

/**
 * @internal PostgreSQL platform strategy for OrderedOutboxTransport.
 *
 * Uses INSERT ... RETURNING id for reliable ID retrieval.
 * Adds xid8 transaction ID column for strict intra-partition ordering
 * via pg_snapshot_xmin() filtering. Requires PostgreSQL >= 13.
 */
final readonly class PostgreSqlOutboxPlatformStrategy implements OutboxPlatformStrategy
{
    public function insertAndReturnId(
        Connection $connection,
        string $tableName,
        array $values,
        array $types,
    ): string {
        $columns = array_keys($values);
        $placeholders = array_fill(0, \count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING id',
            $tableName,
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        // Build positional types array matching column order.
        // The $types array uses column names as keys (e.g., ['created_at' => 'datetime_immutable']),
        // but executeQuery() needs positional indices matching the ? placeholders.
        $positionalTypes = [];
        foreach ($columns as $index => $column) {
            if (isset($types[$column])) {
                $positionalTypes[$index] = $types[$column];
            }
        }

        /** @var int|string|false $id */
        $id = $connection->executeQuery($sql, array_values($values), $positionalTypes)
            ->fetchOne();

        return (string) $id;
    }

    public function buildHeadOfLineFilter(): string
    {
        return ' AND sub.transaction_id < pg_snapshot_xmin(pg_current_snapshot())';
    }

    public function afterTableCreated(Connection $connection, string $tableName): void
    {
        // Register xid8 type mapping so DBAL can introspect tables with this column type.
        // Without this, listTableColumns() throws "Unknown database type xid8".
        $platform = $connection->getDatabasePlatform();
        if (!$platform->hasDoctrineTypeMappingFor('xid8')) {
            $platform->registerDoctrineTypeMapping('xid8', 'bigint');
        }

        $schemaManager = $connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns($tableName);

        if (isset($columns['transaction_id'])) {
            return;
        }

        $connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD COLUMN transaction_id xid8 NOT NULL DEFAULT pg_current_xact_id()',
            $tableName,
        ));
    }
}
