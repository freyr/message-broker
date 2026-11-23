<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use Freyr\MessageBroker\Storage\SchemaInstaller;
use PDO;

/**
 * PostgreSQL schema (14+) of the webhook delivery queue, apart from
 * PostgreSqlSchemaInstaller, so an application that does not use webhooks
 * runs no DDL for it:
 *   - webhook_deliveries → range-partitioned by created_at (one partition per
 *                          UTC day, no DEFAULT partition); managed by
 *                          PostgreSqlDeliveryRetention or pg_partman
 *   - ids                → native UUID (id of the row, message_id of the message)
 *   - headers            → JSONB
 *   - timestamps         → TIMESTAMP(3), UTC by convention
 *   - body column        → BYTEA (the final bytes of the request body)
 *
 * The flow control tables, small, with no partitions and no retention:
 *   - webhook_rate_limit → one token bucket in each row ('' is the global
 *                          bucket, else a lane), PostgreSqlRateLimiter
 *   - webhook_lanes      → the circuit breaker state of each lane that had a
 *                          result, PostgreSqlCircuitBreaker
 *
 * The dead letters of the deliveries go to dead_letters (source
 * "webhook:<lane>"), which PostgreSqlSchemaInstaller creates.
 */
final readonly class PostgreSqlDeliverySchemaInstaller implements SchemaInstaller
{
    private PostgreSqlDeliveryRetention $retention;

    public function __construct(
        private PDO $pdo,
    ) {
        $this->retention = new PostgreSqlDeliveryRetention($pdo);
    }

    public function sql(int $daysAhead): array
    {
        // For a new database: the partition DDL is not idempotent (ATTACH).
        return [...self::tableSql(), ...$this->retention->partitionDdl($daysAhead)];
    }

    public function install(int $daysAhead): void
    {
        foreach (self::tableSql() as $statement) {
            $this->pdo->exec($statement);
        }
        $this->retention->ensureCapacity($daysAhead); // skips partitions that are already attached
    }

    /** @return list<string> */
    private static function tableSql(): array
    {
        // The primary key of a partitioned table must include the partition
        // key created_at. completed_at IS NULL means unfinished; outcome is
        // the final state (1 delivered, 2 dead). The table holds no URL and no
        // secret: the delivery loop reads the subscription of a lane at send
        // time.
        //
        // idx_webhook_deliveries_due serves the claim: one index start for
        // each lane, due rows first. Finished rows leave the index, so it
        // stays small.
        return [
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS webhook_deliveries (
                    id UUID NOT NULL,
                    message_id UUID NOT NULL,
                    lane VARCHAR(64) NOT NULL,
                    message_name VARCHAR(255) NOT NULL,
                    body BYTEA NOT NULL,
                    headers JSONB NOT NULL,
                    created_at TIMESTAMP(3) NOT NULL,
                    available_at TIMESTAMP(3) NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    completed_at TIMESTAMP(3) NULL,
                    outcome SMALLINT NULL,
                    PRIMARY KEY (id, created_at)
                ) PARTITION BY RANGE (created_at)
                SQL,
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_due ON webhook_deliveries (lane, available_at)
                    WHERE completed_at IS NULL
                SQL,
            // The rows hold no rate and no burst: each call gives them. A lane
            // ID has 1 to 64 characters, so '' cannot be a lane. fillfactor 50
            // and no index besides the key: each update can be a HOT update.
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS webhook_rate_limit (
                    name VARCHAR(64) NOT NULL PRIMARY KEY,
                    tokens DOUBLE PRECISION NOT NULL,
                    refilled_at TIMESTAMPTZ NOT NULL
                ) WITH (fillfactor = 50)
                SQL,
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS webhook_lanes (
                    lane VARCHAR(64) NOT NULL PRIMARY KEY,
                    window_started_at TIMESTAMP(3) NOT NULL,
                    requests INTEGER NOT NULL DEFAULT 0,
                    failures INTEGER NOT NULL DEFAULT 0,
                    open_until TIMESTAMP(3) NULL,
                    opened_count INTEGER NOT NULL DEFAULT 0,
                    consecutive_openings INTEGER NOT NULL DEFAULT 0,
                    blocked_at TIMESTAMP(3) NULL
                )
                SQL,
        ];
    }
}
