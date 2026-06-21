<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage;

use Freyr\MessageBroker\Serializer\Format;
use PDOStatement;

final readonly class MySqlPlatform implements Platform
{
    public function insertOutboxSql(): string
    {
        return <<<'SQL'
            INSERT INTO outbox_messages
                (id, lane, message_key, message_name, metadata, body, headers, created_at, available_at, attempts)
            VALUES
                (:id, :lane, :message_key, :message_name, :metadata, :body, :headers, :created_at, :available_at, 0)
            SQL;
    }

    public function tryAcquireLaneSql(): string
    {
        // Non-blocking; returns 1 on success, 0 if another relay owns the lane.
        return "SELECT GET_LOCK(CONCAT('outbox:', :lane), 0)";
    }

    public function selectLanePrefixSql(): string
    {
        // Head eligibility (available_at) is checked in code on the FIRST row
        // only — a backing-off head blocks its whole lane (D17).
        return <<<'SQL'
            SELECT * FROM outbox_messages
            WHERE lane = :lane
            ORDER BY id
            LIMIT :limit
            SQL;
    }

    public function insertDeduplicationSql(): string
    {
        return <<<'SQL'
            INSERT IGNORE INTO message_deduplication (message_id, consumer, message_name, created_at)
            VALUES (:message_id, :consumer, :message_name, :created_at)
            SQL;
    }

    public function bindBody(PDOStatement $statement, string $name, string $body): void
    {
        // MySQL accepts a plain string into both JSON and *BLOB columns.
        $statement->bindValue($name, $body);
    }

    public function readBody(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('Body column is not a string');
        }

        return $value;
    }

    public function schemaSql(Format $format): array
    {
        // E1: one uniform format for the whole outbox. JSON setup stores the
        // payload JSON in a JSON column; Avro setup stores Confluent-framed
        // Avro bytes in a LONGBLOB.
        $bodyType = $format === Format::Avro ? 'LONGBLOB' : 'JSON';

        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS outbox_messages (
                    id CHAR(36) NOT NULL PRIMARY KEY,
                    lane VARCHAR(64) NOT NULL,
                    message_key VARCHAR(255) NOT NULL,
                    message_name VARCHAR(255) NOT NULL,
                    metadata JSON NOT NULL,
                    body {$bodyType} NOT NULL,
                    headers JSON NOT NULL,
                    created_at DATETIME(3) NOT NULL,
                    available_at DATETIME(3) NOT NULL,
                    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    INDEX idx_outbox_drain (lane, id)
                ) ENGINE=InnoDB
                SQL,
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS message_deduplication (
                    message_id CHAR(36) NOT NULL,
                    consumer VARCHAR(128) NOT NULL,
                    message_name VARCHAR(255) NOT NULL,
                    created_at DATETIME(3) NOT NULL,
                    PRIMARY KEY (message_id, consumer)
                ) ENGINE=InnoDB
                SQL,
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS dead_letters (
                    id CHAR(36) NOT NULL PRIMARY KEY,
                    source VARCHAR(255) NOT NULL,
                    message_id CHAR(36) NOT NULL,
                    message_name VARCHAR(255) NOT NULL,
                    body MEDIUMBLOB NOT NULL,
                    headers JSON NOT NULL,
                    error_class VARCHAR(255) NOT NULL,
                    error_message TEXT NOT NULL,
                    error_trace MEDIUMTEXT NOT NULL,
                    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    failed_at DATETIME(3) NOT NULL,
                    replayed_at DATETIME(3) NULL
                ) ENGINE=InnoDB
                SQL,
        ];
    }
}
