# Integration Tests

## Overview

This directory contains integration tests for the Freyr Messenger package, covering:

- **Outbox Pattern** - Event persistence, serialization, and AMQP publishing
- **Inbox Pattern** - Message deduplication, deserialization, and typed message handling
- **End-to-End Flow** - Complete round-trip from outbox → AMQP → inbox

## Prerequisites

- Docker & Docker Compose
- PHP 8.4+
- Composer

## Setup

### 1. Start Infrastructure

```bash
# Start MySQL and RabbitMQ
docker compose up -d

# Wait for services to be healthy
docker compose ps
```

Services:
- **MySQL**: `localhost:3307` (user: `messenger`, pass: `messenger`, db: `messenger_test`)
- **RabbitMQ**: `localhost:5672` (AMQP), `localhost:15672` (Management UI)

### 2. Install Dependencies

```bash
composer install
```

### 3. Run Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suite
vendor/bin/phpunit --testsuite Integration

# Run specific test
vendor/bin/phpunit tests/Integration/OutboxIntegrationTest.php
```

## Test Structure

```
tests/
├── Fixtures/
│   ├── Publisher/           # Outbox domain events
│   │   ├── OrderPlacedEvent.php
│   │   ├── SlaCalculationStartedEvent.php
│   │   └── UserPremiumUpgradedEvent.php
│   ├── Consumer/            # Inbox message DTOs
│   │   ├── OrderPlacedMessage.php
│   │   ├── SlaCalculationStartedMessage.php
│   │   └── UserPremiumUpgradedMessage.php
│   └── AmqpTestSetup.php    # AMQP infrastructure setup
├── Integration/
│   ├── IntegrationTestCase.php       # Base test case
│   ├── OutboxIntegrationTest.php     # Outbox tests (6 tests)
│   ├── InboxIntegrationTest.php      # Inbox tests (5 tests)
│   ├── EndToEndTest.php              # E2E tests (3 tests)
│   └── MessengerNativeTest.php       # Native Messenger API tests (4 tests)
└── README.md (this file)
```

## Test Coverage

### OutboxIntegrationTest

Tests outbox pattern functionality:

✅ Events are saved to `messenger_outbox` table
✅ `message_id` is validated and extracted from events
✅ Routing strategy default convention (first 2 parts → exchange)
✅ `#[AmqpExchange]` attribute override works
✅ `#[AmqpRoutingKey]` attribute override works
✅ Multiple events can be saved to outbox

### InboxIntegrationTest

Tests inbox pattern functionality:

✅ Messages saved to `messenger_inbox` with `message_id` as PK
✅ Deduplication via `INSERT IGNORE` prevents duplicates
✅ `InboxSerializer` deserializes to typed PHP objects
✅ Multiple message types handled correctly
✅ Missing `message_id` throws exception

### EndToEndTest

Tests complete flow:

✅ **Publisher** → Outbox table → AMQP → Inbox table → **Consumer**
✅ Custom exchange override (`#[AmqpExchange]`) works end-to-end
✅ Deduplication prevents duplicate inbox entries

### MessengerNativeTest

Tests using Symfony Messenger's native APIs:

✅ MessageBus dispatch to outbox transport
✅ Transport `get()` → `dispatch()` → `ack()` cycle
✅ Handler invocation with typed inbox messages
✅ Sequential message processing

## Test Messages

### Publisher Side (Outbox Events)

```php
#[MessageName('order.placed')]
class OrderPlacedEvent {
    public function __construct(
        public Id $messageId,    // Required for deduplication
        public Id $orderId,
        public Id $customerId,
        public float $amount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

### Consumer Side (Inbox Messages)

```php
// Same message_name, different class name
class OrderPlacedMessage {
    public function __construct(
        public Id $orderId,      // No messageId (not needed on consumer side)
        public Id $customerId,
        public float $amount,
        public CarbonImmutable $placedAt,
    ) {}
}
```

**Key Point:** Publisher and consumer use **different class names** but **same message_name** for routing.

## AMQP Setup

Tests use `AmqpTestSetup` to configure:

**Exchanges:**
- `order.placed` (topic) - Default convention
- `sla.events` (topic) - Custom via `#[AmqpExchange]`
- `user.premium` (topic) - Default convention

**Queues:**
- `test.inbox` - Receives all test messages

**Bindings:**
- `test.inbox` ← `order.placed` (routing key: `order.#`)
- `test.inbox` ← `sla.events` (routing key: `sla.#`)
- `test.inbox` ← `user.premium` (routing key: `user.*.upgraded`)

## Database Schema

Tests create 3 tables automatically:

1. **`messenger_outbox`** - Outbox events (binary UUID id)
2. **`messenger_inbox`** - Inbox messages (binary UUID id from `message_id`)
3. **`messenger_messages`** - Standard Symfony table (bigint auto-increment)

## Debugging

### View RabbitMQ Management UI

```bash
open http://localhost:15672
# Login: guest / guest
```

### Check Database

```bash
docker compose exec mysql mysql -u messenger -pmessenger messenger_test

# View outbox
SELECT HEX(id), queue_name, JSON_EXTRACT(body, '$.message_name') FROM messenger_outbox;

# View inbox
SELECT HEX(id), queue_name, JSON_EXTRACT(body, '$.message_name') FROM messenger_inbox;
```

### Run Tests with Verbose Output

```bash
vendor/bin/phpunit --verbose --debug
```

## Cleanup

```bash
# Stop services
docker compose down

# Remove volumes (fresh start)
docker compose down -v
```

## Troubleshooting

### Tests fail with "Connection refused"

**Solution:** Ensure Docker services are running and healthy:
```bash
docker compose ps
# All services should show (healthy)
```

### MySQL connection errors

**Solution:** Wait longer for MySQL to be ready:
```bash
docker compose logs mysql
# Look for "ready for connections"
```

### RabbitMQ not accessible

**Solution:** Check RabbitMQ logs:
```bash
docker compose logs rabbitmq
# Should see "Server startup complete"
```

### Tests hang

**Solution:** Increase Docker resource limits (Memory: 4GB+, CPUs: 2+)

## CI/CD Integration

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: messenger_test
          MYSQL_USER: messenger
          MYSQL_PASSWORD: messenger
        ports:
          - 3307:3306
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=5s
          --health-timeout=3s
          --health-retries=10

      rabbitmq:
        image: rabbitmq:3.13-management-alpine
        ports:
          - 5672:5672
        options: >-
          --health-cmd="rabbitmq-diagnostics ping"
          --health-interval=5s
          --health-timeout=3s
          --health-retries=10

    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
          extensions: pdo_mysql, amqp
      - run: composer install
      - run: vendor/bin/phpunit
```

## Performance

Typical test execution time:
- **Outbox tests**: ~0.5s (6 tests)
- **Inbox tests**: ~0.3s (5 tests)
- **End-to-end tests**: ~1.0s (3 tests)
- **Native Messenger tests**: ~0.5s (4 tests)
- **Total**: ~2.3s (18 tests, 62 assertions)

## Contributing

When adding new tests:
1. Extend `IntegrationTestCase` for database/AMQP setup
2. Add test fixtures to `tests/Fixtures/`
3. Update `AmqpTestSetup` if new exchanges/queues needed
4. Run full test suite before committing
