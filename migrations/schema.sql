-- Freyr Message Broker Database Schema
-- Application-Managed Tables Only

-- Drop tables if they exist (for clean CI setup)
DROP TABLE IF EXISTS message_broker_deduplication;

-- Message Broker Deduplication Table
-- Used by DeduplicationMiddleware for inbox idempotency
-- Custom application table (not managed by Symfony Messenger)
CREATE TABLE IF NOT EXISTS message_broker_deduplication (
    message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    message_name VARCHAR(255) NOT NULL,
    processed_at DATETIME NOT NULL,
    INDEX idx_message_name (message_name),
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: messenger_outbox and messenger_messages tables are now auto-managed by Symfony Messenger
-- They will be created automatically on first worker run (auto_setup: true)
-- No manual migration required for these tables.
