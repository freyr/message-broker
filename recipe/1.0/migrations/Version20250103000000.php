<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Freyr Message Broker tables
 *
 * Creates three tables for the message broker:
 * - messenger_outbox: Outbox pattern with binary UUID v7
 * - messenger_inbox: Inbox pattern with binary UUID v7 for deduplication
 * - messenger_messages: Standard Symfony Messenger table for failed/DLQ
 */
final class Version20250103000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Freyr Message Broker tables (outbox, inbox, messages)';
    }

    public function up(Schema $schema): void
    {
        // Create messenger_outbox table with binary UUID v7
        $this->addSql("
            CREATE TABLE messenger_outbox (
                id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT 'outbox',
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create messenger_inbox table with binary UUID v7 (from message_id header)
        $this->addSql("
            CREATE TABLE messenger_inbox (
                id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT 'inbox',
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create standard messenger_messages table (for failed/DLQ with BIGINT)
        $this->addSql("
            CREATE TABLE messenger_messages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_available (queue_name, available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_outbox');
        $this->addSql('DROP TABLE messenger_inbox');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
