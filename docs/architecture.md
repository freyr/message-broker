# Messenger Package Architecture

## Overview

This package implements the **Inbox** and **Outbox** patterns for reliable distributed messaging in Symfony applications. It provides transactional guarantees for both publishing events to external systems and consuming events from external sources.

## Design Principles

1. **Transactional Integrity:** Events are stored in database within the same transaction as business data
2. **At-Least-Once Delivery:** Events guaranteed to be delivered, with deduplication on consumer side
3. **Decoupling:** Async processing doesn't block business operations
4. **Framework Integration:** Built on Symfony Messenger for reliability and developer experience
5. **Package Independence:** Designed as standalone package with minimal external dependencies

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     Your Application                         │
│                                                              │
│  ┌──────────────┐                    ┌──────────────┐      │
│  │   Domain     │──── dispatch ────→ │  Event Bus   │      │
│  │   Events     │                    │  (Messenger) │      │
│  └──────────────┘                    └──────┬───────┘      │
│                                              │              │
└──────────────────────────────────────────────┼──────────────┘
                                               │
                    ┌──────────────────────────┴─────────────────────┐
                    │                                                │
                    ↓                                                ↓
         ┌────────────────────┐                        ┌────────────────────┐
         │   OUTBOX PATTERN   │                        │   INBOX PATTERN    │
         │                    │                        │                    │
         │  Publish Events    │                        │  Consume Events    │
         │  Reliably          │                        │  with Dedup        │
         └─────────┬──────────┘                        └──────────┬─────────┘
                   │                                              │
                   ↓                                              ↑
            ┌──────────────┐                           ┌──────────────────┐
            │   AMQP/MQ    │──── events ──────────────→│  AMQP Consumer   │
            │  (External)  │                           │   (php-amqplib)  │
            └──────────────┘                           └──────────────────┘
```

## Package Structure

```
messenger/
├── src/
│   ├── Inbox/                      # Inbox Pattern Implementation
│   │   ├── Command/                # Console commands
│   │   ├── Handler/                # Message handlers & registry
│   │   ├── Message/                # Message DTOs
│   │   ├── Serializer/             # Message serialization
│   │   └── Transport/              # Custom Doctrine transport with dedup
│   │
│   └── Outbox/                     # Outbox Pattern Implementation
│       ├── Command/                # Console commands
│       ├── EventBridge/            # Outbox-to-AMQP bridge
│       ├── Routing/                # AMQP routing strategies
│       └── Serializer/             # Event serialization
│
├── config/                         # Configuration examples
├── docs/                           # Documentation
└── README.md                       # Quick start guide
```

## Data Flow

### Outbox Pattern (Publishing)

1. **Business Transaction:** Application processes business logic
2. **Event Dispatch:** Domain events dispatched to Messenger
3. **Outbox Storage:** Events stored in `messenger_messages` table (same transaction)
4. **Transaction Commit:** Business data + events committed atomically
5. **Async Processing:** Worker consumes from outbox transport
6. **Bridge Processing:** `OutboxToAmqpBridge` republishes to AMQP
7. **External Delivery:** Events delivered to RabbitMQ/external systems

### Inbox Pattern (Consuming)

1. **AMQP Consumption:** `ConsumeAmqpToMessengerCommand` consumes from RabbitMQ
2. **Extract message_id:** Event ID extracted for deduplication
3. **Inbox Storage:** Message wrapped and sent to inbox transport
4. **Deduplication:** `DoctrineDedupConnection` uses INSERT IGNORE with message_id as PK
5. **Async Processing:** Worker consumes from inbox transport (SKIP LOCKED)
6. **Handler Dispatch:** `InboxEventMessageHandler` routes to domain handlers via registry
7. **Business Processing:** Domain event handlers execute business logic

## Key Components

### Inbox Components

| Component | Purpose |
|-----------|---------|
| `DoctrineDedupConnection` | Custom Doctrine connection using INSERT IGNORE for deduplication |
| `DoctrineDedupTransport` | Wrapper transport with dedup connection |
| `DoctrineDedupTransportFactory` | Factory for creating dedup transport instances |
| `InboxEventMessage` | Message DTO wrapping external events |
| `InboxEventSerializer` | Serializes messages with message_id in headers |
| `InboxEventMessageHandler` | Routes messages to domain handlers |
| `EventHandlerRegistry` | Registry mapping event names to handlers |
| `ConsumeAmqpToMessengerCommand` | AMQP consumer dispatching to Messenger |

### Outbox Components

| Component | Purpose |
|-----------|---------|
| `OutboxEventSerializer` | Serializes domain events to JSON with semantic names |
| `OutboxToAmqpBridge` | Consumes from outbox and publishes to AMQP |
| `AmqpRoutingStrategyInterface` | Strategy for AMQP routing configuration |
| `DefaultAmqpRoutingStrategy` | Default routing implementation |
| `CleanupOutboxCommand` | Cleanup old processed messages |

## Technology Stack

- **Symfony Messenger:** Core messaging framework
- **Doctrine DBAL:** Database operations and custom types
- **php-amqplib:** AMQP protocol implementation
- **freyr/identity:** UUID v7 generation and binary storage
- **MySQL/MariaDB:** Primary database (binary UUID support)

## Deduplication Strategy

### Inbox Deduplication
- **Method:** Binary UUID v7 as primary key
- **Mechanism:** INSERT IGNORE on `messenger_messages.id`
- **Key:** `message_id` from AMQP message headers
- **Benefit:** Database-level deduplication, no application logic needed

### Outbox Guarantees
- **Method:** Transactional outbox with exactly-once publishing
- **Mechanism:** Bridge tracks processed messages
- **Benefit:** No duplicates sent to AMQP under normal conditions

## Scaling Considerations

### Horizontal Scaling
- **Multiple Workers:** Run multiple `messenger:consume` processes
- **Multiple AMQP Consumers:** One per queue for parallel processing
- **Database Locking:** `FOR UPDATE SKIP LOCKED` prevents conflicts

### Performance
- **Binary UUIDs:** Efficient 16-byte storage vs 36-byte strings
- **Indexed Columns:** Optimized queries on status, queue, timestamps
- **Batch Processing:** Messenger handles batching internally

### Monitoring
- `messenger:stats` - View queue depths
- `messenger:failed:show` - Review failed messages
- Database queries - Monitor inbox/outbox table sizes

## Security Considerations

1. **Input Validation:** Always validate external event payloads
2. **Type Safety:** Strong typing prevents injection attacks
3. **Database Security:** Use parameterized queries (handled by Doctrine)
4. **AMQP Credentials:** Store in environment variables
5. **Error Messages:** Don't leak sensitive info in error logs

## Extension Points

1. **Custom Routing:** Implement `AmqpRoutingStrategyInterface`
2. **Custom Serialization:** Extend serializers for custom types
3. **Custom Handlers:** Register handlers via `app.event_handler` tag
4. **Monitoring:** Add custom middleware to Messenger bus
5. **Cleanup Policies:** Customize retention in cleanup command

## Future Enhancements

- [ ] Dead letter queue support
- [ ] Metrics and monitoring integration (Prometheus)
- [ ] Multi-tenancy support
- [ ] Event versioning and schema evolution
- [ ] Saga pattern support
- [ ] GraphQL subscription integration
