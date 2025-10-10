# Outbox Pattern - Transactional Consistency

## Principle

The Outbox pattern ensures that domain events are published reliably alongside business data changes within a single database transaction.

## How It Works

**Single Transaction Guarantee:**
1. Your business logic updates domain data (e.g., creates an order)
2. The event is inserted into the `messenger_outbox` table
3. Both operations succeed or fail together - atomic commit
4. If the transaction rolls back, neither the order nor the event is saved

**Asynchronous Publishing:**
- After commit, workers consume from outbox and publish to AMQP
- Publishing happens outside the original transaction
- At-least-once delivery: events may be published multiple times
- Consumers must handle deduplication (inbox pattern)

## Benefits

**Consistency:** No event is lost if business operation succeeds - guaranteed by database ACID properties

**Reliability:** Events survive database crashes before publishing - they're safely persisted in the outbox table

**Decoupling:** Publishing failures don't affect business transactions - workers retry independently

**Performance:** No synchronous AMQP calls during business operations - low latency for users

## Architecture

```
[Business Transaction] → [Domain Data + Outbox Event] → COMMIT
                                    ↓
                          [Worker: messenger:consume outbox]
                                    ↓
                            [Publish to AMQP]
```

## Key Components

- **OutboxMessage** - Marker interface all outbox events must implement
- **OutboxToAmqpBridge** - Worker handler that publishes events to AMQP
- **messenger_outbox table** - Stores events until successfully published

## Delivery Guarantees

- **At-least-once delivery** - Events published one or more times
- **No events lost** - If business transaction commits, event will eventually be published
