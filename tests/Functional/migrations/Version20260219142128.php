<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260219142128 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create message_broker_deduplication table for deduplication tracking';
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
        $table->addIndex(['processed_at'], 'idx_dedup_processed_at');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('message_broker_deduplication');
    }
}
