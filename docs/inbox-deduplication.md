# Inbox Pattern - Deduplication

## Principle

The Inbox pattern with deduplication ensures that each message is processed exactly once, even if it arrives multiple times from AMQP.

## How It Works

**Middleware-Based Deduplication:**
1. Message arrives from AMQP with `MessageIdStamp` containing UUID v7
2. `DeduplicationMiddleware` runs within database transaction (priority -10)
3. Attempts to INSERT into `message_broker_deduplication` table
4. If INSERT succeeds → new message → process handler
5. If INSERT fails (duplicate key) → duplicate message → skip handler

**Transactional Guarantee:**
- Deduplication check and handler execution in same transaction
- Both commit together or rollback together
- If handler fails, deduplication entry rolls back → message can be retried

## Benefits

**Exactly-once processing:** Message processed only once despite multiple deliveries

**Idempotency:** Handlers don't need manual duplicate checking - middleware handles it

**Atomic safety:** Handler changes committed only if deduplication succeeds

**Crash resilience:** Failed processing allows retry without duplicate execution

## Architecture

```
[AMQP Message] → [InboxSerializer] → [MessageIdStamp + MessageNameStamp]
                        ↓
            [doctrine_transaction middleware]
                        ↓
            [DeduplicationMiddleware (priority -10)]
                        ↓
        [INSERT INTO message_broker_deduplication]
                        ↓
            [Success?] → [Handler] → [COMMIT]
                 ↓
            [Duplicate?] → [Skip Handler]
```

## Key Components

- **DeduplicationMiddleware** - Checks duplicates before handler execution
- **MessageIdStamp** - Contains UUID v7 message identifier
- **message_broker_deduplication table** - Binary UUID v7 primary key prevents duplicates
- **ReceivedStamp** - Triggers deduplication check (only for consumed messages)

**Note:** Deduplication uses MessageIdStamp + PHP class FQN. The PHP FQN is obtained from `$envelope->getMessage()::class`.

## Delivery Guarantees

- **Exactly-once processing** - Handler executes exactly once per unique message ID
- **At-most-once in database** - Deduplication table has unique constraint on message_id
- **Retry-safe** - Failed messages can retry without creating duplicates
- **Transactional** - Deduplication and business logic commit atomically

## Deduplication Table

**Structure:**
- `message_id` (binary(16), primary key) - UUID v7 from MessageIdStamp
- `message_name` (varchar) - PHP FQN for monitoring (e.g., 'App\Message\OrderPlaced')
- `processed_at` (datetime) - Timestamp for cleanup

**Cleanup:**
- Old entries can be removed periodically
- Recommended: Keep 30+ days for audit trail
- Command: `message-broker:deduplication-cleanup --days=30`
