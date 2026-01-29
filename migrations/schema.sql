-- Freyr Message Broker Database Schema
-- 3-Table Architecture: Outbox, Deduplication, Failed Messages

-- Drop tables if they exist (for clean CI setup)
DROP TABLE IF EXISTS messenger_outbox;
DROP TABLE IF EXISTS message_broker_deduplication;
DROP TABLE IF EXISTS messenger_messages;

-- 1. messenger_outbox
-- Purpose: Stores domain events for transactional outbox pattern
-- Note: Uses BIGINT AUTO_INCREMENT (Symfony Messenger Doctrine transport requirement)
CREATE TABLE messenger_outbox (
    id BIGINT AUTO_INCREMENT NOT NULL PRIMARY KEY,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX idx_queue_name (queue_name),
    INDEX idx_available_at (available_at),
    INDEX idx_delivered_at (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. message_broker_deduplication
-- Purpose: Tracks processed messages to prevent duplicate execution
CREATE TABLE message_broker_deduplication (
    message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    message_name VARCHAR(255) NOT NULL,
    processed_at DATETIME NOT NULL,
    INDEX idx_message_name (message_name),
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. messenger_messages
-- Purpose: Stores failed messages from all transports for unified monitoring
CREATE TABLE messenger_messages (
    id BIGINT AUTO_INCREMENT NOT NULL PRIMARY KEY,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX idx_queue_name (queue_name),
    INDEX idx_available_at (available_at),
    INDEX idx_delivered_at (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
