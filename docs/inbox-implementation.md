# Inbox Pattern with Symfony Messenger - Implementation Summary

## ✅ What We Built

### **Custom Doctrine Transport with INSERT IGNORE Deduplication**

Successfully implemented a custom Messenger transport that extends Symfony's `DoctrineTransport` to add MySQL `INSERT IGNORE` functionality for automatic deduplication.

## 📋 Components Created

### 1. **DoctrineDedupConnection**
`src/Shared/Infrastructure/Messenger/Transport/DoctrineDedupConnection.php`

- Extends `Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection`
- Overrides `send()` method to use `INSERT IGNORE` SQL
- Adds `content_hash` column with UNIQUE constraint
- Uses `message_id` from message headers for deduplication
- Falls back to SHA-256 hash of body if no `message_id`

### 2. **DoctrineDedupTransport**
`src/Shared/Infrastructure/Messenger/Transport/DoctrineDedupTransport.php`

- Thin wrapper extending `DoctrineTransport`
- Uses custom `DoctrineDedupConnection`

### 3. **DoctrineDedupTransportFactory**
`src/Shared/Infrastructure/Messenger/Transport/DoctrineDedupTransportFactory.php`

- Implements `TransportFactoryInterface`
- Registers custom `inbox://` DSN scheme
- Auto-wired with `@doctrine.dbal.default_connection`

### 4. **InboxEventMessage**
`src/Shared/Infrastructure/Messenger/Message/InboxEventMessage.php`

- Wrapper message for events from AMQP
- Contains: `eventName`, `payload`, `eventId`, `sourceQueue`

### 5. **InboxEventMessageHandler**
`src/Shared/Infrastructure/Messenger/Handler/InboxEventMessageHandler.php`

- Message handler with `#[AsMessageHandler(fromTransport: 'inbox')]`
- Routes messages to domain event handlers via `EventHandlerRegistry`

### 6. **ConsumeAmqpToMessengerCommand**
`src/Shared/Infrastructure/Command/ConsumeAmqpToMessengerCommand.php`

- Consumes from AMQP (php-amqplib)
- Extracts `message_id` for deduplication
- Dispatches `InboxEventMessage` to Messenger inbox transport
- Always ACKs AMQP (deduplication handled by transport)

## ⚙️ Configuration

### **messenger.yaml**
```yaml
transports:
    inbox:
        dsn: 'inbox://default?queue_name=inbox'
        options:
            auto_setup: true

routing:
    'Sescom\FSM\Shared\Infrastructure\Messenger\Message\InboxEventMessage': inbox
```

### **services_inbox.yaml**
```yaml
Sescom\FSM\Shared\Infrastructure\Messenger\Transport\DoctrineDedupTransportFactory:
    arguments:
        $connection: '@doctrine.dbal.default_connection'
    tags: ['messenger.transport_factory']
```

## 🔄 Flow

```
1. AMQP (RabbitMQ)
   ↓
2. ConsumeAmqpToMessengerCommand (php-amqplib)
   - Extract message_id
   - Create InboxEventMessage
   - Dispatch to Messenger
   ↓
3. Messenger Inbox Transport (inbox://)
   - INSERT IGNORE with content_hash
   - Deduplication automatic
   - Stores in messenger_messages table
   ↓
4. messenger:consume inbox
   - SKIP LOCKED (built-in)
   - Worker pool support
   ↓
5. InboxEventMessageHandler
   - Routes to EventHandlerRegistry
   ↓
6. Domain Event Handlers (SlaBreachedHandler, etc.)
   - Business logic execution
```

## 🚀 Usage

**Terminal 1: AMQP → Messenger**
```bash
docker compose run --rm php bin/console inbox:ingest --queue=fsm.client_request_lifecycle
```

**Terminal 2: Process Inbox (Messenger)**
```bash
docker compose run --rm php bin/console messenger:consume inbox -vv
```

**Terminal 3: Trigger Events**
```bash
docker compose run --rm php bin/console fsm:trigger-fake-sla-event calculated
```

## ✨ Benefits

✅ **Leverages Messenger Infrastructure**
- Built-in SKIP LOCKED
- Automatic retry/failed handling
- Worker management
- `messenger:stats` monitoring

✅ **INSERT IGNORE Deduplication**
- No exceptions on duplicates
- MySQL-native performance
- Event-ID based deduplication

✅ **Scalable Architecture**
- Multiple AMQP consumers (by queue)
- Multiple inbox workers (worker pool)
- Standard Messenger commands

✅ **Clean Separation**
- AMQP consumption (custom, necessary)
- Message processing (Messenger, standard)

## ⚠️ Remaining Tasks

1. Fix remaining PHPStan errors (4 minor type hints)
2. Add `content_hash` column migration to `messenger_messages` table
3. Test deduplication end-to-end
4. Add monitoring/metrics
5. Document scaling strategies in README

## 🎯 Key Insight

**You were right!** Using Messenger for inbox processing is cleaner than custom commands. We kept the custom AMQP consumer (necessary for deduplication logic) but leverage Messenger's infrastructure for everything else.

The custom transport with INSERT IGNORE is the key innovation that makes this work seamlessly.
