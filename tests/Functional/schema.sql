DROP TABLE IF EXISTS message_broker_deduplication;

CREATE TABLE message_broker_deduplication (
    message_id   BINARY(16)   NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    message_name VARCHAR(255) NOT NULL,
    processed_at DATETIME     NOT NULL,
    INDEX idx_dedup_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
