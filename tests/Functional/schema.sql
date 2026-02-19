-- Freyr Message Broker - Test Environment Schema
-- This schema is ONLY for tests and includes all tables needed for testing
-- For production plugin users, see migrations/schema.sql

-- Drop tables if they exist (for clean setup)
DROP TABLE IF EXISTS message_broker_deduplication;
DROP TABLE IF EXISTS messenger_outbox;
DROP TABLE IF EXISTS messenger_messages;

-- Message Broker Deduplication Table
-- Application-managed table (not auto-created by Symfony)
CREATE TABLE message_broker_deduplication (
    message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    message_name VARCHAR(255) NOT NULL,
    processed_at DATETIME NOT NULL,
    INDEX idx_dedup_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messenger Outbox Table
-- Pre-created for tests (production uses auto_setup: true)
-- Schema matches Symfony Messenger's auto-generated structure
CREATE TABLE messenger_outbox (
    id BIGINT AUTO_INCREMENT NOT NULL,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX IDX_75EA56E0FB7336F0 (queue_name),
    INDEX IDX_75EA56E0E3BD61CE (available_at),
    INDEX IDX_75EA56E016BA31DB (delivered_at),
    PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messenger Messages Table (for failed transport)
-- Pre-created for tests (production uses auto_setup: true)
-- Schema matches Symfony Messenger's auto-generated structure
CREATE TABLE messenger_messages (
    id BIGINT AUTO_INCREMENT NOT NULL,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX IDX_75EA56E0FB7336F0 (queue_name),
    INDEX IDX_75EA56E0E3BD61CE (available_at),
    INDEX IDX_75EA56E016BA31DB (delivered_at),
    PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
