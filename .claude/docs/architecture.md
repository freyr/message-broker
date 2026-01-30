# Architecture Overview

## Outbox Pattern (Publishing Events)

Events are stored in a database table within the same transaction as business data, then asynchronously published using a **strategy-based architecture**:

```
Domain Event → Event Bus (Messenger) → Outbox Transport (doctrine://)
→ messenger_outbox table (transactional) → messenger:consume outbox
→ OutboxToAmqpBridge → AMQP (with routing strategy)
```

**Key Features:**
- **Generic Handler:** Single `__invoke()` method handles all events
- **AMQP Routing Strategy:** Determines exchange, routing key, and headers
- **Message ID Validation:** Enforces `messageId` (UUID v7) in all events
- **Convention-Based Routing:** Automatic routing with attribute overrides

## Inbox Pattern (Consuming Events)

Events are consumed from AMQP natively with deduplication using middleware-based approach:

```
AMQP Transport (native Symfony Messenger)
→ InboxSerializer translates 'type' header (semantic name → FQN)
→ Native Symfony Serializer deserializes body + stamps from X-Message-Stamp-* headers
→ Routes to handler (based on PHP class)
→ DeduplicationMiddleware (checks message_broker_deduplication table)
→ If duplicate: skip handler | If new: INSERT + process
→ Application Handler → Business Logic (all within transaction)
```

## Key Innovation: "Fake FQN" Pattern + Native Stamp Handling

- **Native Transport**: Uses Symfony Messenger's built-in AMQP transport (no custom commands)
- **Split Serializers**: Separate serializers for inbox and outbox flows:
  - **OutboxSerializer**: Extracts semantic name from `#[MessageName]` attribute during encoding (publishing)
  - **InboxSerializer**: Translates semantic name to FQN during decoding (consuming), uses default encoding for failed message retries
- **Why Split?**: Inbox messages don't have `#[MessageName]` attribute, so they need default encoding when being retried/stored in failed transport
- **Native Stamp Handling**: Stamps (MessageIdStamp, MessageNameStamp) automatically serialized/deserialized via `X-Message-Stamp-*` headers by Symfony
- **DeduplicationMiddleware**: Runs AFTER `doctrine_transaction` middleware (priority -10), ensuring deduplication checks happen within the transaction
- **Atomic Guarantees**: If handler succeeds, both deduplication entry and business logic changes are committed atomically
- **Retry Safety**: If handler fails, transaction rolls back, allowing message to be retried

## Directory Structure

```
src/
├── Command/                    # Console Commands
│   └── DeduplicationStoreCleanup.php
├── Doctrine/                   # Doctrine Integration
│   └── Type/
│       └── IdType.php          # Binary UUID v7 Doctrine type
├── Inbox/                      # Inbox Pattern Implementation
│   ├── DeduplicationDbalStore.php
│   ├── DeduplicationMiddleware.php
│   ├── DeduplicationStore.php
│   └── MessageIdStamp.php
├── Outbox/                     # Outbox Pattern Implementation
│   ├── EventBridge/
│   │   ├── OutboxMessage.php
│   │   └── OutboxToAmqpBridge.php
│   ├── Routing/
│   │   ├── AmqpRoutingKey.php
│   │   ├── AmqpRoutingStrategyInterface.php
│   │   ├── DefaultAmqpRoutingStrategy.php
│   │   └── MessengerTransport.php
│   └── MessageName.php
├── Serializer/                 # Serialization Infrastructure
│   ├── Normalizer/
│   │   ├── CarbonImmutableNormalizer.php
│   │   └── IdNormalizer.php
│   ├── InboxSerializer.php
│   ├── MessageNameStamp.php
│   └── OutboxSerializer.php
└── FreyrMessageBrokerBundle.php
```

## Important Implementation Details

### 3-Table Architecture

The package uses dedicated tables:
- **`messenger_outbox`** - Auto-managed by Symfony (auto_setup: true) with BIGINT AUTO_INCREMENT
- **`message_broker_deduplication`** - Application-managed (manual migration) with BINARY(16) UUID v7 PK
- **`messenger_messages`** - Auto-managed by Symfony (auto_setup: true) for failed messages

### DeduplicationMiddleware

Runs AFTER `doctrine_transaction` middleware (priority -10):
- Checks `MessageIdStamp` on incoming messages (restored automatically from headers)
- Uses PHP class FQN from `$envelope->getMessage()::class` as message name
- Attempts INSERT into `message_broker_deduplication` table
- If duplicate (UniqueConstraintViolationException): skips handler execution
- If new: processes message normally
- Transaction commits: deduplication entry + handler changes are atomic
- Transaction rolls back: deduplication entry is rolled back, message can be retried

### AMQP Infrastructure Requirements

Native AMQP transport consumption assumes RabbitMQ infrastructure is already configured:
- Queues must exist
- Exchanges must exist
- Queue-to-exchange bindings must be configured

Symfony Messenger AMQP transport only consumes from existing queues; it does not declare or bind queues/exchanges (unless auto_setup is enabled).

### AMQP Consumer ACK Behavior

Native AMQP transport ACKs messages after successful handler execution. Messages are NACK'd if they fail validation (InboxSerializer) or if handlers throw exceptions.

### Message Format

AMQP messages use native Symfony serialization with semantic `type` header:

```
Headers:
  type: order.placed  (semantic message name)
  X-Message-Stamp-MessageIdStamp: [{"messageId":"01234567-89ab..."}]

Body (only business data):
{
  "orderId": "550e8400-e29b-41d4-a716-446655440000",
  "totalAmount": 123.45,
  "placedAt": "2025-10-08T13:30:00+00:00"
}
```

- **type header**: Semantic message name (e.g., `order.placed`) - language-agnostic
- **X-Message-Stamp-*** headers: Symfony stamps (MessageIdStamp, etc.) - auto-generated by OutboxToAmqpBridge
- **Body**: Native Symfony serialization of the message object (business data only, no messageId)
- **messageId**: NOT in payload - it's transport metadata in MessageIdStamp header

### Transactional Guarantees

- **Outbox**: Events are only published if the business transaction commits successfully (atomicity)
- **Inbox**: Deduplication entry and handler changes are committed in the same transaction (atomicity)

### At-Least-Once Delivery

System guarantees events are delivered at least once; consumers must be idempotent (enforced by DeduplicationMiddleware).

## Scaling Considerations

- Run multiple AMQP consumers: one per queue (e.g., `messenger:consume amqp_orders`) - recommended approach
- Run multiple outbox workers: `messenger:consume outbox`
- All workers support horizontal scaling with SKIP LOCKED

## Namespace Convention

All classes use the `Freyr\MessageBroker` namespace:
- `Freyr\MessageBroker\Inbox\*`
- `Freyr\MessageBroker\Outbox\*`
