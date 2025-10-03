# Database Schema

## Table Strategy

The Freyr Messenger package uses a **3-table approach** for optimal performance and isolation:

1. **`messenger_outbox`** - Dedicated table for outbox pattern (high write throughput)
2. **`messenger_inbox`** - Dedicated table for inbox pattern (INSERT IGNORE deduplication)
3. **`messenger_messages`** - Standard Symfony Messenger table for DLQ, failed, and other queues

### Why 3 Tables?

**Performance Benefits:**
- ✅ **No lock contention** between inbox/outbox operations
- ✅ **Isolated indexes** optimized per use case
- ✅ **Independent scaling** - partition/shard tables separately
- ✅ **Different retention policies** - cleanup inbox vs outbox independently

**Simplified Monitoring:**
- ✅ **Unified failed queue** - all failures in one place (`messenger_messages` with `queue_name='failed'`)
- ✅ **Standard tooling** - Symfony's `messenger:failed:*` commands work out of the box

## Table Schemas

### 1. Outbox Table (`messenger_outbox`)

High write throughput, transactional event storage.

```sql
CREATE TABLE messenger_outbox (
    id BINARY(16) NOT NULL PRIMARY KEY,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL DEFAULT 'outbox',
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX idx_available_at (available_at),
    INDEX idx_delivered_at (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Indexes:**
- `available_at` - Fast lookup for pending messages
- `delivered_at` - Cleanup queries (find old delivered messages)

### 2. Inbox Table (`messenger_inbox`)

Binary UUID v7 primary key for deduplication via INSERT IGNORE.

```sql
CREATE TABLE messenger_inbox (
    id BINARY(16) NOT NULL PRIMARY KEY,  -- message_id from AMQP (UUID v7)
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL DEFAULT 'inbox',
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX idx_available_at (available_at),
    INDEX idx_delivered_at (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Key Feature:**
- `id` is the `message_id` from incoming AMQP message (not auto-increment)
- `INSERT IGNORE` on primary key provides database-level deduplication

### 3. Standard Messenger Table (`messenger_messages`)

Shared table for failed messages, DLQ, and other transports.

```sql
CREATE TABLE messenger_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
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
```

**Used For:**
- `queue_name='failed'` - Failed inbox/outbox messages
- `queue_name='dlq'` - Dead-letter queue for unmatched events
- Other custom transports

## Migration

### Doctrine Migration Example

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251002000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create messenger tables for inbox/outbox patterns';
    }

    public function up(Schema $schema): void
    {
        // Outbox table
        $this->addSql('
            CREATE TABLE messenger_outbox (
                id BINARY(16) NOT NULL PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT "outbox",
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_available_at (available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Inbox table
        $this->addSql('
            CREATE TABLE messenger_inbox (
                id BINARY(16) NOT NULL PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL DEFAULT "inbox",
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_available_at (available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Standard messenger table (for failed, dlq, etc.)
        $this->addSql('
            CREATE TABLE messenger_messages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_queue_name (queue_name),
                INDEX idx_available_at (available_at),
                INDEX idx_delivered_at (delivered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messenger_outbox');
        $this->addSql('DROP TABLE IF EXISTS messenger_inbox');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }
}
```

## Configuration

### Messenger Transport Configuration

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            # Outbox - dedicated table
            outbox:
                dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer'
                options:
                    auto_setup: false  # Use migrations instead

            # Inbox - dedicated table with custom transport
            inbox:
                dsn: 'inbox://default?table_name=messenger_inbox&queue_name=inbox'
                serializer: 'Freyr\Messenger\Inbox\Serializer\TypedInboxSerializer'
                options:
                    auto_setup: false  # Use migrations instead

            # AMQP - external broker
            amqp:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\Messenger\Outbox\Serializer\OutboxEventSerializer'

            # DLQ - uses standard messenger_messages table
            dlq:
                dsn: 'doctrine://default?queue_name=dlq'
                options:
                    auto_setup: false

            # Failed - uses standard messenger_messages table
            failed:
                dsn: 'doctrine://default?queue_name=failed'
                options:
                    auto_setup: false

        routing:
            'App\Domain\Event\*': outbox
```

### Services Configuration

```yaml
# config/services.yaml
services:
    # Cleanup command with custom table name
    Freyr\Messenger\Outbox\Command\CleanupOutboxCommand:
        arguments:
            $connection: '@doctrine.dbal.default_connection'
            $tableName: 'messenger_outbox'
            $queueName: 'outbox'
```

## Maintenance

### Cleanup Outbox Messages

```bash
# Remove delivered messages older than 7 days from messenger_outbox
php bin/console messenger:cleanup-outbox --days=7 --batch-size=1000
```

**Why needed?**
Symfony Messenger marks messages as `delivered_at` but doesn't auto-delete them. This prevents table growth.

### Cleanup Inbox Messages

Create a similar command or use database events:

```sql
-- MySQL Event Scheduler (optional)
CREATE EVENT cleanup_inbox_messages
ON SCHEDULE EVERY 1 DAY
DO
  DELETE FROM messenger_inbox
  WHERE delivered_at IS NOT NULL
    AND delivered_at < NOW() - INTERVAL 7 DAY
  LIMIT 10000;
```

### Monitor Failed Messages

```bash
# View failed messages (from both inbox and outbox)
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry

# Remove old failed messages
php bin/console messenger:failed:remove <id>
```

## Performance Tuning

### Partitioning (Optional)

For very high throughput, partition tables by date:

```sql
-- Partition outbox by month
ALTER TABLE messenger_outbox
PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
    PARTITION p202510 VALUES LESS THAN (202511),
    PARTITION p202511 VALUES LESS THAN (202512),
    PARTITION p202512 VALUES LESS THAN (202601),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

### Index Optimization

Monitor slow queries and add composite indexes as needed:

```sql
-- Example: Optimize cleanup queries
ALTER TABLE messenger_outbox
ADD INDEX idx_delivered_cleanup (delivered_at, queue_name);

-- Example: Optimize inbox deduplication lookups
-- (Primary key on id already handles this)
```

## Table Size Monitoring

```sql
-- Check table sizes
SELECT
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
    table_rows
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
    AND table_name LIKE 'messenger_%'
ORDER BY (data_length + index_length) DESC;

-- Check delivered vs pending messages
SELECT
    'outbox' as transport,
    COUNT(*) as total,
    SUM(CASE WHEN delivered_at IS NULL THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered
FROM messenger_outbox
UNION ALL
SELECT
    'inbox',
    COUNT(*),
    SUM(CASE WHEN delivered_at IS NULL THEN 1 ELSE 0 END),
    SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END)
FROM messenger_inbox;
```
