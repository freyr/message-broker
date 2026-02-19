<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Message Broker Deduplication table.
 *
 * Creates deduplication tracking table with binary UUID v7 for middleware-based deduplication.
 *
 * The table name must match the `message_broker.inbox.deduplication.table_name`
 * configuration value (defaults to 'message_broker_deduplication').
 */
final class Version20250103000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create message_broker_deduplication table for middleware-based deduplication';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('message_broker_deduplication');
        $table->addColumn('message_id', Types::BINARY, [
            'length' => 16,
            'fixed' => true,
            'notnull' => true,
            'comment' => '(DC2Type:id_binary)',
        ]);
        $table->addColumn('message_name', Types::STRING, [
            'length' => 255,
            'notnull' => true,
        ]);
        $table->addColumn('processed_at', Types::DATETIME_MUTABLE, [
            'notnull' => true,
        ]);
        $table->setPrimaryKey(['message_id']);
        $table->addIndex(['message_name'], 'idx_dedup_message_name');
        $table->addIndex(['processed_at'], 'idx_dedup_processed_at');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('message_broker_deduplication');
    }
}
