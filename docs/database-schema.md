# Database Schema - 3-Table Architecture

## Overview

The Freyr Message Broker uses a **3-table architecture** for optimal performance and separation of concerns:

1. **`messenger_outbox`** - Dedicated outbox table for publishing events
2. **`message_broker_deduplication`** - Deduplication tracking (binary UUID v7 PK)
3. **`messenger_messages`** - Standard table for failed messages (shared monitoring)

## Benefits

- ✅ Native AMQP transport consumption (no custom commands)
- ✅ Middleware-based deduplication (more native to Symfony Messenger)
- ✅ Transactional guarantees (deduplication + handler in same transaction)
- ✅ Optimised indexes per use case
- ✅ Independent cleanup policies
- ✅ Unified failed message monitoring
- ✅ Flexible: Direct AMQP→Handler or AMQP→Inbox→Handler

## Table Schemas

### 1. messenger_outbox

**Purpose:** Stores domain events for transactional outbox pattern.

**Schema:**
```sql
CREATE TABLE messenger_outbox (
    id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
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

**Key Points:**
- Binary UUID v7 primary key (sortable by creation time)
- Standard Symfony Messenger Doctrine transport structure
- Used exclusively for outbox pattern
- Consumed by `OutboxToAmqpBridge`
- Published events use `OutboxSerializer`

**Cleanup:**
```bash
# Optional - Symfony marks messages as delivered but doesn't auto-delete
php bin/console messenger:cleanup-outbox --days=7
```

### 2. message_broker_deduplication

**Purpose:** Tracks processed messages to prevent duplicate execution.

**Schema:**
```sql
CREATE TABLE message_broker_deduplication (
    message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    message_name VARCHAR(255) NOT NULL,
    processed_at DATETIME NOT NULL,
    INDEX idx_message_name (message_name),
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Key Points:**
- Binary UUID v7 from `MessageIdStamp` header
- `message_name` stores PHP FQN (e.g., 'App\Message\OrderPlaced')
- Primary key constraint enforces deduplication
- Used by `DeduplicationMiddleware`
- INSERT attempted within transaction (priority -10)
- Duplicate key violation → skip handler

**Cleanup:**
```bash
# Remove old idempotency records (recommended: keep 30+ days)
php bin/console message-broker:deduplication-cleanup --days=30
```

### 3. messenger_messages

**Purpose:** Stores failed messages from all transports for unified monitoring.

**Schema:**
```sql
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
```

**Key Points:**
- Standard Symfony Messenger Doctrine transport
- Used for failed transport (`queue_name='failed'`)
- Auto-increment ID (not UUID)
- Shared across all transport failures

**Monitoring:**
```bash
# View failed messages
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry
```

## Migration Example

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250103000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create message broker tables (outbox, deduplication, failed)';
    }

    public function up(Schema $schema): void
    {
        // 1. Outbox table
        $this->addSql("
            CREATE TABLE messenger_outbox (
                id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
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
        ");

        // 2. Deduplication table
        $this->addSql("
            CREATE TABLE message_broker_deduplication (
                message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
                message_name VARCHAR(255) NOT NULL,
                processed_at DATETIME NOT NULL,
                INDEX idx_message_name (message_name),
                INDEX idx_processed_at (processed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3. Failed messages table (standard)
        $this->addSql("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_outbox');
        $this->addSql('DROP TABLE message_broker_deduplication');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
```

## Transport Configuration

```yaml
framework:
  messenger:
    failure_transport: failed

    transports:
      # Outbox transport - uses messenger_outbox table
      outbox:
        dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'

      # AMQP publish transport
      amqp:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'
        options:
          auto_setup: false

      # AMQP consumption transport
      amqp_orders:
        dsn: '%env(MESSENGER_AMQP_DSN)%'
        serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'
        options:
          auto_setup: false
          queue:
            name: 'orders_queue'

      # Failed transport - uses messenger_messages table
      failed:
        dsn: 'doctrine://default?queue_name=failed'
        options:
          auto_setup: false
```

## Message Flow

### Publishing (Outbox Pattern)
```
Domain Event → messenger.bus.default
  ↓ (routing: outbox)
messenger_outbox table (INSERT within transaction)
  ↓ (messenger:consume outbox)
OutboxToAmqpBridge → AMQP
```

### Consuming (Inbox Pattern)
```
AMQP Queue → amqp_orders transport
  ↓ (InboxSerializer: semantic name → FQN)
DeduplicationMiddleware
  ↓ (INSERT into message_broker_deduplication)
Handler → Business Logic
  ↓ (COMMIT: deduplication + handler changes atomic)
ACK to AMQP
```

### Failed Messages
```
Any Handler Exception
  ↓ (retry exhausted)
messenger_messages table (queue_name='failed')
  ↓ (manual retry)
php bin/console messenger:failed:retry
```

## Doctrine Type Registration

**config/packages/doctrine.yaml:**
```yaml
doctrine:
    dbal:
        types:
            id_binary: Freyr\MessageBroker\Doctrine\Type\IdType
```

**services.yaml:**
```yaml
services:
  Freyr\MessageBroker\Doctrine\Type\IdType:
    tags:
      - { name: 'doctrine.dbal.types', type: 'id_binary' }
```

## Index Optimisation

**messenger_outbox:**
- `idx_queue_name` - Fast filtering by queue
- `idx_available_at` - Efficient worker polling
- `idx_delivered_at` - Quick cleanup queries

**message_broker_deduplication:**
- Primary key on `message_id` - Enforces uniqueness
- `idx_message_name` - Monitoring by message type
- `idx_processed_at` - Efficient cleanup

**messenger_messages:**
- `idx_queue_name` - Failed message filtering
- `idx_available_at` - Retry scheduling
- `idx_delivered_at` - Cleanup queries

## Cleanup Strategies

**Outbox Table:**
- Messages marked as delivered (delivered_at IS NOT NULL)
- Optional cleanup to prevent table growth
- Safe to delete after successful AMQP publish

**Deduplication Table:**
- Keep records for audit trail (recommended: 30+ days)
- Balance: longer retention = better duplicate detection
- Shorter retention = smaller table, faster queries

**Failed Messages Table:**
- Manually inspect and retry failed messages
- Delete after successful retry or investigation
- Keep for debugging and monitoring

## Performance Considerations

- **Binary UUID v7:** Chronologically sortable, better index performance than UUID v4
- **Separate tables:** Independent cleanup, optimised indexes per use case
- **SKIP LOCKED:** Native support for horizontal scaling
- **Transactional deduplication:** Atomic guarantees without distributed locks

## Constraint Design Rationale

This section documents the reasoning behind key database constraint decisions to preserve architectural knowledge.

### Primary Key: Single Column (message_id)

**Decision:** Use `message_id BINARY(16) PRIMARY KEY` only, not composite `(message_id, message_name)`

**Rationale:**
- UUID v7 provides **global uniqueness** across all message types
- 2^122 possible UUIDs → collision probability negligible
- `message_name` indexed for queries but not uniqueness enforcement
- Composite key would be redundant (message_id alone guarantees uniqueness)

**Trade-off:** Theoretical risk of same UUID across different message types, but:
- UUID v7 generation ensures this never happens in practice
- Simplifies queries (single-column lookup)
- Reduces index size

### No Foreign Keys

**Decision:** No FK constraints to domain entities

**Rationale:**
- **Intentional architectural decoupling**
- Event-driven system with eventual consistency
- Deduplication table is standalone idempotency store
- External events may reference entities that don't exist yet
- No cascade delete requirements (entries cleaned via TTL)

**Trade-off:** Can't enforce referential integrity at DB level, but:
- Deduplication doesn't depend on entity existence
- Messages carry all necessary data (self-contained)
- Loose coupling enables independent scaling

### VARCHAR(255) for message_name

**Decision:** 255 character limit for PHP FQN

**Rationale:**
- Typical FQN length: 50-100 chars (e.g., `App\Domain\Event\OrderPlaced`)
- Longest realistic FQN: ~150 chars
- 255 provides safe buffer without waste
- MySQL VARCHAR efficiently stores actual length (no padding)

**Validation:** No truncation risk in practice

### DATETIME Precision (Second-Level)

**Decision:** DATETIME (not DATETIME(6) with microseconds)

**Rationale:**
- Deduplication based on message_id (UUID), not timestamp
- `processed_at` used for cleanup queries (day-level granularity)
- No ordering requirements within same second
- Microsecond precision unnecessary overhead

**Storage Savings:** 2 bytes per row (minor but measurable at scale)
