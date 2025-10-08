<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Message Broker Deduplication table
 *
 * Creates deduplication tracking table with binary UUID v7 for middleware-based deduplication.
 */
final class Version20250103000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create message_broker_deduplication table for middleware-based deduplication';
    }

    public function up(Schema $schema): void
    {
        // Create message_broker_deduplication table with binary UUID v7
        $this->addSql("
            CREATE TABLE message_broker_deduplication (
                message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
                message_name VARCHAR(255) NOT NULL,
                processed_at DATETIME NOT NULL,
                INDEX idx_message_name (message_name),
                INDEX idx_processed_at (processed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE message_broker_deduplication');
    }
}
