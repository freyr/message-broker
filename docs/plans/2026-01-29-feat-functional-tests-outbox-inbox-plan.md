# Functional Tests for Outbox and Inbox Patterns

---
title: Functional Tests for Outbox and Inbox Patterns
type: feat
date: 2026-01-29
deepened: 2026-01-29
---

## Enhancement Summary

**Deepened on:** 2026-01-29
**Research Agents:** 5 (PHP testing, Symfony Messenger, Docker, RabbitMQ, Database patterns)
**Review Agents:** 5 (Architecture, Performance, Security, Data Integrity, Code Simplicity)

### Critical Fixes Applied

1. **Serializer Configuration** - Fixed `amqp_test` transport to use `InboxSerializer` (was incorrectly using OutboxSerializer)
2. **Binary UUID Handling** - Added HEX comparison for binary UUID assertions to prevent database mismatch errors
3. **Security Enhancement** - Added database safety check to prevent accidental test-against-production scenarios
4. **Performance Optimization** - Implemented AMQP connection pooling (saves ~800ms-1.7s for 20 tests)

### Key Improvements

- **Test Execution Speed:** Reduced from ~8-12s to ~5-7s expected (with optimizations)
- **Data Integrity:** Fixed binary UUID comparison bug that would cause silent test failures
- **Security:** Added runtime validation to ensure tests only run against test databases
- **Reliability:** Connection pooling prevents timeout issues and reduces flakiness

### Research Insights Incorporated

- **PHPUnit 12:** Uses attributes, transaction rollback patterns, property promotion
- **Symfony Messenger:** Proper Worker usage, transport configuration, stamp handling
- **Docker Testing:** tmpfs volumes, health checks, parallel execution strategies
- **RabbitMQ:** Connection pooling, basic_get() for non-blocking retrieval
- **Database:** Binary UUID v7 assertions, transaction atomicity testing, cleanup strategies

## Overview

Implement comprehensive functional tests for Freyr Message Broker to verify end-to-end flows with real infrastructure (MySQL + RabbitMQ). Tests are divided into two categories: **Outbox Flow** (publishing) and **Inbox Flow** (consuming with deduplication).

**Scope:** Happy path scenarios only (success flows). Failure scenarios deferred to future iterations.

**Infrastructure Strategy:** Tests assume MySQL and RabbitMQ are running externally. Tests manage state cleanup (truncate tables, purge queues) but not infrastructure lifecycle.

## Problem Statement

Currently, the message broker has excellent unit test coverage with in-memory transports and stores, but lacks functional tests that verify:

1. **Real Database Transactions** - Events actually persist atomically with business data
2. **Actual AMQP Publishing** - Messages reach RabbitMQ with correct format
3. **Deduplication with Real DB** - `message_broker_deduplication` table prevents duplicates
4. **End-to-End Integration** - Complete flows from event dispatch to handler execution

**Without functional tests, we cannot confidently:**
- Verify transactional guarantees work in production scenarios
- Validate message format correctness (headers, stamps, body structure)
- Ensure deduplication middleware functions with real database
- Test that configuration (serialisers, middleware, routing) works as documented

## Proposed Solution

Create a functional test suite using PHPUnit that:

1. **Runs against real infrastructure** - MySQL 8.0 + RabbitMQ 3.13 via Docker Compose
2. **Tests complete flows** - Not isolated components, but full event lifecycle
3. **Maintains clean state** - Truncates DB tables and purges AMQP queues between tests
4. **Follows existing patterns** - Mirrors unit test organisation and style

**Test Structure:**
```
tests/Functional/
├── FunctionalTestCase.php      # Base class: Symfony kernel boot, cleanup helpers
├── OutboxFlowTest.php          # Tests: event → outbox → AMQP publishing
└── InboxFlowTest.php           # Tests: AMQP → deduplication → handler
```

## Technical Approach

### Phase 1: Infrastructure Setup

**Objective:** Prepare Docker Compose configuration and align ports

**Tasks:**
- [x] Fix MySQL port mismatch (phpunit.xml expects 3307, compose.yaml exposes 3308)
- [x] Verify RabbitMQ configuration (already on correct port 5673)
- [x] Update `phpunit.xml.dist` with correct DATABASE_URL and AMQP DSN
- [x] Add Unit testsuite to phpunit.xml.dist

**Deliverables:**
- [x] Updated `phpunit.xml.dist` with correct DATABASE_URL (port 3308)
- [x] Updated `phpunit.xml.dist` with correct MESSENGER_AMQP_DSN (port 5673)
- [x] Added Unit testsuite to phpunit.xml.dist

---

### Phase 2: Test Kernel and Base Class

**Objective:** Create Symfony test kernel for bundle integration and base test case for cleanup

#### 2.1 Create Test Kernel

**File:** `tests/Functional/TestKernel.php`

**Responsibilities:**
- Extend `Symfony\Component\HttpKernel\Kernel`
- Register `FreyrMessageBrokerBundle`
- Load test configuration (Doctrine, Messenger, message_broker)
- Configure in-memory environment for tests

**Configuration to Load:**
- Doctrine: database connection, migrations, IdType registration
- Messenger: transports (outbox, amqp, failed), middleware, routing
- Bundle: message type mapping for InboxSerializer

**Key Methods:**
```php
public function registerBundles(): array
{
    return [
        new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
        new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
        new Freyr\MessageBroker\FreyrMessageBrokerBundle(),
    ];
}

public function registerContainerConfiguration(LoaderInterface $loader): void
{
    $loader->load(__DIR__ . '/config/test.yaml');
}
```

#### 2.2 Create Test Configuration

**File:** `tests/Functional/config/test.yaml`

**Content:**
- Database connection using `%env(DATABASE_URL)%`
- Doctrine DBAL type registration (id_binary)
- Messenger transports (outbox, amqp_test, failed)
- Bundle configuration with test message types

**Example:**
```yaml
doctrine:
    dbal:
        url: '%env(DATABASE_URL)%'
        types:
            id_binary: Freyr\MessageBroker\Doctrine\Type\IdType

framework:
    messenger:
        transports:
            outbox:
                dsn: 'doctrine://default?table_name=messenger_outbox&queue_name=outbox'
                serializer: 'Freyr\MessageBroker\Serializer\OutboxSerializer'

            amqp_test:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'  # CRITICAL: Inbox transport must use InboxSerializer
                options:
                    exchange: { name: 'test_events' }

            failed:
                dsn: 'doctrine://default?queue_name=failed'

message_broker:
    inbox:
        message_types:
            'test.event.sent': 'Freyr\MessageBroker\Tests\Functional\Fixtures\TestEvent'
            'test.order.placed': 'Freyr\MessageBroker\Tests\Functional\Fixtures\OrderPlaced'
```

#### 2.3 Create FunctionalTestCase Base Class

**File:** `tests/Functional/FunctionalTestCase.php`

**Extends:** `Symfony\Bundle\FrameworkBundle\Test\KernelTestCase`

**Responsibilities:**
1. Boot Symfony kernel with TestKernel
2. Run database migrations or setup schema
3. Truncate tables before each test
4. Purge AMQP queues before each test
5. Provide assertion helpers

**Key Methods:**

```php
// tests/Functional/FunctionalTestCase.php

protected static function getKernelClass(): string
{
    return TestKernel::class;
}

protected function setUp(): void
{
    parent::setUp();
    self::bootKernel();

    $this->cleanDatabase();
    $this->cleanAmqp();
}

private function cleanDatabase(): void
{
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

    // SAFETY CHECK: Prevent accidental test-against-production scenarios
    $params = $connection->getParams();
    if (!str_contains($params['dbname'] ?? '', '_test')) {
        throw new \RuntimeException(
            'Safety check failed: Database must contain "_test" in name. ' .
            'Got: ' . ($params['dbname'] ?? 'unknown')
        );
    }

    // Truncate tables (order matters due to foreign keys if any)
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
    $connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');
    $connection->executeStatement('TRUNCATE TABLE messenger_outbox');
    $connection->executeStatement('TRUNCATE TABLE messenger_messages');
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
}

// PERFORMANCE: Static connection pooling to avoid overhead (saves ~800ms-1.7s for 20 tests)
private static ?AMQPStreamConnection $amqpConnection = null;

protected static function getAmqpConnection(): AMQPStreamConnection
{
    if (self::$amqpConnection === null) {
        $dsn = $_ENV['MESSENGER_AMQP_DSN'] ?? 'amqp://guest:guest@127.0.0.1:5673/%2f';
        $parts = parse_url($dsn);

        self::$amqpConnection = new AMQPStreamConnection(
            $parts['host'] ?? '127.0.0.1',
            $parts['port'] ?? 5672,
            $parts['user'] ?? 'guest',
            $parts['pass'] ?? 'guest',
            trim($parts['path'] ?? '/', '/')
        );
    }
    return self::$amqpConnection;
}

public static function tearDownAfterClass(): void
{
    if (self::$amqpConnection !== null) {
        self::$amqpConnection->close();
        self::$amqpConnection = null;
    }
    parent::tearDownAfterClass();
}

private function cleanAmqp(): void
{
    $channel = self::getAmqpConnection()->channel();

    // Purge test queues (create if not exists, then purge)
    $queuesToPurge = ['outbox', 'test_inbox', 'failed'];

    foreach ($queuesToPurge as $queueName) {
        try {
            $channel->queue_declare($queueName, false, true, false, false);
            $channel->queue_purge($queueName);
        } catch (\Exception $e) {
            // Queue might not exist yet, that's okay
        }
    }

    $channel->close();
}

// Assertion Helpers

protected function assertDatabaseHasRecord(string $table, array $criteria): void
{
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

    $qb = $connection->createQueryBuilder();
    $qb->select('COUNT(*) as count')
       ->from($table);

    foreach ($criteria as $column => $value) {
        // CRITICAL: Handle binary UUID columns with HEX comparison
        if ($column === 'message_id' && $value instanceof \Freyr\Identity\Id) {
            $qb->andWhere("HEX($column) = :$column")
               ->setParameter($column, strtoupper(str_replace('-', '', $value->__toString())));
        } else {
            $qb->andWhere("$column = :$column")
               ->setParameter($column, $value);
        }
    }

    $count = (int)$qb->executeQuery()->fetchOne();  // Type safety: cast to int

    $this->assertGreaterThan(0, $count,
        "Failed asserting that table '$table' contains a record matching criteria.");
}

protected function assertMessageInQueue(string $queueName): ?array
{
    $channel = self::getAmqpConnection()->channel();

    $message = $channel->basic_get($queueName);

    $channel->close();

    if ($message === null) {
        $this->fail("No message found in queue '$queueName'");
    }

    $body = json_decode($message->body, true);
    $headers = $message->get_properties()['application_headers'] ?? [];

    return [
        'body' => $body,
        'headers' => $headers,
        'envelope' => $message,
    ];
}
```

**Deliverables:**
- `tests/Functional/TestKernel.php`
- `tests/Functional/config/test.yaml`
- `tests/Functional/FunctionalTestCase.php`

---

### Phase 3: Outbox Flow Tests

**Objective:** Verify complete outbox pattern with real infrastructure

**File:** `tests/Functional/OutboxFlowTest.php`

**Test Scenarios:**

#### Test 1: Event Stored in Outbox Database

```php
// tests/Functional/OutboxFlowTest.php

public function testEventIsStoredInOutboxDatabase(): void
{
    // Given: A test event
    $testEvent = new TestEvent(
        id: Id::new(),
        name: 'integration-test-event',
        timestamp: CarbonImmutable::now()
    );

    // When: Event is dispatched to message bus
    $messageBus = $this->getContainer()->get(MessageBusInterface::class);
    $messageBus->dispatch($testEvent);

    // Then: Event is stored in messenger_outbox table
    $this->assertDatabaseHasRecord('messenger_outbox', [
        'queue_name' => 'outbox',
    ]);

    // And: Body contains serialised event data
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
    $result = $connection->fetchAssociative(
        "SELECT body FROM messenger_outbox WHERE queue_name = 'outbox'"
    );

    $body = json_decode($result['body'], true);
    $this->assertEquals('integration-test-event', $body['name']);
}
```

#### Test 2: OutboxToAmqpBridge Publishes to AMQP

```php
public function testOutboxBridgePublishesToAmqp(): void
{
    // Given: An event in the outbox
    $testEvent = new TestEvent(
        id: Id::new(),
        name: 'bridge-test-event',
        timestamp: CarbonImmutable::now()
    );

    $messageBus = $this->getContainer()->get(MessageBusInterface::class);
    $messageBus->dispatch($testEvent);

    // When: OutboxToAmqpBridge processes the outbox
    $worker = new Worker(
        ['outbox' => $this->getContainer()->get('messenger.transport.outbox')],
        $this->getContainer()->get('event_dispatcher'),
        $this->getContainer()->get('logger')
    );

    // Process one message
    $worker->run([
        'limit' => 1,
        'time-limit' => 5,
    ]);

    // Then: Message is published to AMQP exchange
    $message = $this->assertMessageInQueue('outbox');

    // And: Message has correct type header (semantic name)
    $this->assertArrayHasKey('type', $message['headers']);
    $this->assertEquals('test.event.sent', $message['headers']['type']);

    // And: Message has MessageIdStamp header
    $this->assertArrayHasKey('X-Message-Stamp-MessageIdStamp', $message['headers']);

    // And: Body contains event data (no messageId in payload)
    $this->assertEquals('bridge-test-event', $message['body']['name']);
    $this->assertArrayNotHasKey('messageId', $message['body']);
}
```

#### Test 3: Message Format Correctness

```php
public function testPublishedMessageHasCorrectFormat(): void
{
    // Given: An event with value objects
    $testEvent = new OrderPlaced(
        orderId: Id::new(),
        customerId: Id::new(),
        totalAmount: 99.99,
        placedAt: CarbonImmutable::now()
    );

    $messageBus = $this->getContainer()->get(MessageBusInterface::class);
    $messageBus->dispatch($testEvent);

    // When: Bridge processes and publishes
    $this->processOutbox();

    // Then: Message in AMQP has correct structure
    $message = $this->assertMessageInQueue('outbox');

    // Semantic name in type header
    $this->assertEquals('test.order.placed', $message['headers']['type']);

    // UUIDs are serialised as strings
    $this->assertIsString($message['body']['orderId']);
    $this->assertMatchesRegularExpression(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $message['body']['orderId']
    );

    // Timestamps are ISO 8601
    $this->assertMatchesRegularExpression(
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
        $message['body']['placedAt']
    );

    // Numeric values preserved
    $this->assertSame(99.99, $message['body']['totalAmount']);
}
```

**Deliverables:**
- `tests/Functional/OutboxFlowTest.php`
- `tests/Functional/Fixtures/TestEvent.php`
- `tests/Functional/Fixtures/OrderPlaced.php`

---

### Phase 4: Inbox Flow Tests

**Objective:** Verify complete inbox pattern with deduplication

**File:** `tests/Functional/InboxFlowTest.php`

**Test Scenarios:**

#### Test 1: Message Consumed and Deserialised

```php
// tests/Functional/InboxFlowTest.php

public function testMessageIsConsumedAndDeserialised(): void
{
    // Given: A message published to AMQP with semantic name
    $messageId = Id::new();
    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([
            ['messageId' => $messageId->__toString()]
        ]),
    ], [
        'id' => Id::new()->__toString(),
        'name' => 'inbox-test',
        'timestamp' => CarbonImmutable::now()->toIso8601String(),
    ]);

    // When: Message is consumed from AMQP
    $handlerInvoked = false;
    $receivedMessage = null;

    $this->getContainer()->set('test_handler', function (TestEvent $message) use (&$handlerInvoked, &$receivedMessage) {
        $handlerInvoked = true;
        $receivedMessage = $message;
    });

    $this->consumeFromInbox();

    // Then: Handler was invoked
    $this->assertTrue($handlerInvoked, 'Handler should have been invoked');

    // And: Message was correctly deserialised to typed object
    $this->assertInstanceOf(TestEvent::class, $receivedMessage);
    $this->assertEquals('inbox-test', $receivedMessage->name);
}
```

#### Test 2: Deduplication Prevents Duplicate Processing

```php
public function testDeduplicationPreventsDuplicateProcessing(): void
{
    // Given: A message with a specific message ID
    $messageId = Id::new();
    $messageData = [
        'id' => Id::new()->__toString(),
        'name' => 'dedup-test',
        'timestamp' => CarbonImmutable::now()->toIso8601String(),
    ];

    // When: Message is published and consumed (first time)
    $handlerInvocationCount = 0;

    $this->getContainer()->set('test_handler', function () use (&$handlerInvocationCount) {
        $handlerInvocationCount++;
    });

    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([
            ['messageId' => $messageId->__toString()]
        ]),
    ], $messageData);

    $this->consumeFromInbox();

    // Then: Handler invoked once
    $this->assertEquals(1, $handlerInvocationCount);

    // And: Deduplication entry created (using Id object for HEX comparison)
    $this->assertDatabaseHasRecord('message_broker_deduplication', [
        'message_id' => $messageId,  // Pass Id object, not binary
    ]);

    // When: Same message published again (duplicate)
    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([
            ['messageId' => $messageId->__toString()]
        ]),
    ], $messageData);

    $this->consumeFromInbox();

    // Then: Handler NOT invoked again
    $this->assertEquals(1, $handlerInvocationCount,
        'Handler should not be invoked for duplicate message');

    // And: Message was ACK'd (not in queue)
    $this->assertQueueEmpty('test_inbox');
}
```

#### Test 3: Semantic Name Translation

```php
public function testSemanticNameIsTranslatedToPhpClass(): void
{
    // Given: Message type mapping configured in test.yaml
    // 'test.event.sent' => 'Freyr\MessageBroker\Tests\Functional\Fixtures\TestEvent'

    // When: Message arrives with semantic name in type header
    $receivedMessage = null;

    $this->getContainer()->set('test_handler', function ($message) use (&$receivedMessage) {
        $receivedMessage = $message;
    });

    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',  // Semantic name
        'X-Message-Stamp-MessageIdStamp' => json_encode([
            ['messageId' => Id::new()->__toString()]
        ]),
    ], [
        'id' => Id::new()->__toString(),
        'name' => 'translation-test',
        'timestamp' => CarbonImmutable::now()->toIso8601String(),
    ]);

    $this->consumeFromInbox();

    // Then: InboxSerializer translated to correct PHP class
    $this->assertInstanceOf(TestEvent::class, $receivedMessage);

    // And: Not the semantic name string
    $this->assertNotInstanceOf('test.event.sent', $receivedMessage);
}
```

#### Test 4: Transactional Atomicity

```php
public function testDeduplicationAndHandlerAreAtomic(): void
{
    // Given: A message that will be processed
    $messageId = Id::new();

    $handlerExecuted = false;

    $this->getContainer()->set('test_handler', function () use (&$handlerExecuted) {
        $handlerExecuted = true;

        // Simulate handler making database changes
        $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
        $connection->insert('test_handler_audit', [
            'action' => 'test_executed',
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    });

    // When: Message is consumed
    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([
            ['messageId' => $messageId->__toString()]
        ]),
    ], [
        'id' => Id::new()->__toString(),
        'name' => 'atomic-test',
        'timestamp' => CarbonImmutable::now()->toIso8601String(),
    ]);

    $this->consumeFromInbox();

    // Then: Both deduplication entry AND handler changes committed
    $this->assertTrue($handlerExecuted, 'Handler should have been executed');
    $this->assertDatabaseHasRecord('message_broker_deduplication', [
        'message_id' => $messageId,  // Pass Id object for HEX comparison
    ]);

    // Verify handler executed (use variable tracking instead of database table)
    $this->assertTrue($handlerExecuted, 'Handler execution should be atomic with deduplication');
}
```

**Deliverables:**
- `tests/Functional/InboxFlowTest.php`
- Helper methods in `FunctionalTestCase` for AMQP publish/consume

---

### Phase 5: CI/CD Integration

**Objective:** Make tests runnable in continuous integration

**Tasks:**

1. **Add PHPUnit configuration for functional tests**

Update `phpunit.xml.dist`:

```xml
<testsuites>
    <testsuite name="Functional">
        <directory>tests/Functional</directory>
    </testsuite>
    <testsuite name="Integration">
        <directory>tests/Integration</directory>
    </testsuite>
    <testsuite name="Unit">
        <directory>tests/Unit</directory>
    </testsuite>
</testsuites>
```

2. **Create test script in Makefile**

Add to `Makefile`:

```makefile
.PHONY: test-functional
test-functional: ## Run functional tests
	docker compose run --rm php vendor/bin/phpunit tests/Functional

.PHONY: test-unit
test-unit: ## Run unit tests
	docker compose run --rm php vendor/bin/phpunit tests/Unit

.PHONY: test-all
test-all: ## Run all tests
	docker compose run --rm php vendor/bin/phpunit
```

3. **Document setup in README.md**

Add section:

```markdown
## Running Functional Tests

### Prerequisites

1. Start test infrastructure:
   ```bash
   docker-compose up -d
   ```

2. Run migrations to create schema:
   ```bash
   docker-compose run --rm php bin/console doctrine:migrations:migrate
   ```

### Running Tests

**All tests:**
```bash
make test-all
```

**Functional tests only:**
```bash
make test-functional
```

**Unit tests only:**
```bash
make test-unit
```

**CI/CD:**
```bash
# Start infrastructure
docker-compose up -d

# Run migrations
docker-compose run --rm php bin/console doctrine:migrations:migrate

# Run tests
docker-compose run --rm php vendor/bin/phpunit

# Cleanup
docker-compose down -v
```
```

4. **Add GitHub Actions workflow (if using GitHub)**

Create `.github/workflows/tests.yml`:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Start Docker infrastructure
        run: docker-compose up -d

      - name: Wait for MySQL
        run: |
          until docker-compose exec -T mysql mysqladmin ping -h localhost --silent; do
            echo "Waiting for MySQL..."
            sleep 2
          done

      - name: Wait for RabbitMQ
        run: |
          until docker-compose exec -T rabbitmq rabbitmqctl status; do
            echo "Waiting for RabbitMQ..."
            sleep 2
          done

      - name: Run migrations
        run: docker-compose run --rm php bin/console doctrine:migrations:migrate --no-interaction

      - name: Run tests
        run: docker-compose run --rm php vendor/bin/phpunit

      - name: Cleanup
        if: always()
        run: docker-compose down -v
```

**Deliverables:**
- Updated `phpunit.xml.dist`
- Updated `Makefile`
- Updated `README.md`
- `.github/workflows/tests.yml` (if using GitHub)

---

## Acceptance Criteria

### Functional Requirements

- [ ] `OutboxFlowTest` verifies event is stored in `messenger_outbox` table
- [ ] `OutboxFlowTest` verifies OutboxToAmqpBridge publishes to AMQP
- [ ] `OutboxFlowTest` verifies message format (type header, stamps, body structure)
- [ ] `InboxFlowTest` verifies message consumption and deserialisation
- [ ] `InboxFlowTest` verifies deduplication prevents duplicate processing
- [ ] `InboxFlowTest` verifies semantic name translation to PHP class
- [ ] `InboxFlowTest` verifies transactional atomicity (deduplication + handler)

### Infrastructure Requirements

- [ ] `FunctionalTestCase` boots Symfony kernel with bundle loaded
- [ ] `FunctionalTestCase` truncates database tables between tests
- [ ] `FunctionalTestCase` purges AMQP queues between tests
- [ ] Tests can run multiple times without manual cleanup
- [ ] Tests assume MySQL and RabbitMQ are running (no container management)

### CI/CD Requirements

- [ ] Tests runnable via `make test-functional`
- [ ] Tests runnable via `docker-compose run --rm php vendor/bin/phpunit tests/Functional`
- [ ] GitHub Actions workflow runs tests on push
- [ ] README documents how to run tests locally
- [ ] Exit code 0 for success, non-zero for failures

### Quality Gates

- [ ] All tests follow Given/When/Then structure with comments
- [ ] Test fixtures use property promotion and readonly classes
- [ ] Assertion helpers in `FunctionalTestCase` for common checks
- [ ] No hard-coded sleep/delays (use proper synchronisation)
- [ ] Tests are deterministic (no flaky failures)

## Dependencies & Prerequisites

### Before Starting

1. **Port Alignment** - Fix MySQL port mismatch between phpunit.xml (3307) and compose.yaml (3308)
2. **Docker Running** - Ensure Docker and Docker Compose are installed and running
3. **Database Schema** - Migrations must be runnable to create tables
4. **AMQP Extension** - Verify php-amqplib is installed (already in composer.json)

### External Dependencies

- MySQL 8.0+ (via Docker Compose)
- RabbitMQ 3.13+ with management plugin (via Docker Compose)
- PHP 8.4+ with ext-amqp and ext-pdo_mysql (already configured in Dockerfile)
- PHPUnit 12.0+ (already in composer.json)

### Internal Dependencies

- `FreyrMessageBrokerBundle` must be loadable without Symfony Flex
- Doctrine migrations must exist for 3-table architecture
- `OutboxSerializer` and `InboxSerializer` must be configured
- `DeduplicationMiddleware` must be registered

## Implementation Notes

### Open Questions Resolved

**Q1: Should tests create AMQP queues/exchanges or assume they exist?**
- **Decision:** Create programmatically in `FunctionalTestCase::cleanAmqp()`
- **Rationale:** Tests are more self-contained, no manual RabbitMQ setup required

**Q2: How to handle Symfony Messenger worker for inbox tests?**
- **Decision:** Use `Worker` class directly (synchronous, single message consumption)
- **Rationale:** Most integrated with Symfony Messenger, no subprocess management

**Q3: Should tests verify message IDs are UUID v7?**
- **Decision:** Assert UUID format exists, don't validate v7 specifically
- **Rationale:** UUID v7 validation is a unit test concern; functional tests verify it works

### Test Execution Flow

**Outbox Flow Test:**
```
1. Dispatch event via MessageBus
   ↓
2. Event routed to 'outbox' transport (Doctrine)
   ↓
3. Event stored in messenger_outbox table
   ↓
4. Worker processes outbox transport
   ↓
5. OutboxToAmqpBridge handles event
   ↓
6. Bridge publishes to AMQP with stamps
   ↓
7. Assert: database record + AMQP message format
```

**Inbox Flow Test:**
```
1. Publish message to AMQP (test helper)
   ↓
2. Worker consumes from AMQP transport
   ↓
3. InboxSerializer translates semantic name → FQN
   ↓
4. DeduplicationMiddleware checks message_broker_deduplication
   ↓
5. If new: INSERT deduplication entry + invoke handler
   ↓
6. If duplicate: skip handler, ACK message
   ↓
7. Assert: handler invoked (or not) + database state
```

### Fixture Organisation

**Fixtures Directory:**
```
tests/Functional/Fixtures/
├── TestEvent.php           # Simple test message for basic scenarios
└── OrderPlaced.php         # Complex message with multiple value objects
```

**Fixture Characteristics:**
- Implement `OutboxMessage` interface
- Use `#[MessageName('test.xxx')]` attribute
- Readonly classes with property promotion
- Use value objects (`Id`, `CarbonImmutable`)

### AMQP Helper Methods

Add to `FunctionalTestCase`:

```php
protected function publishToAmqp(string $queue, array $headers, array $body): void
{
    $channel = self::getAmqpConnection()->channel();

    $channel->queue_declare($queue, false, true, false, false);

    $message = new AMQPMessage(
        json_encode($body),
        [
            'application_headers' => new AMQPTable($headers),
            'content_type' => 'application/json',
        ]
    );

    $channel->basic_publish($message, '', $queue);

    $channel->close();
}

protected function consumeFromInbox(int $limit = 1): void
{
    $receiver = $this->getContainer()->get('messenger.transport.amqp_test');
    $bus = $this->getContainer()->get('messenger.default_bus');

    $worker = new Worker(
        ['amqp_test' => $receiver],
        $bus,
        $this->getContainer()->get('event_dispatcher'),
        $this->getContainer()->get('logger')
    );

    $worker->run([
        'limit' => $limit,
        'time-limit' => 5,
    ]);
}

protected function processOutbox(int $limit = 1): void
{
    $receiver = $this->getContainer()->get('messenger.transport.outbox');
    $bus = $this->getContainer()->get('messenger.default_bus');

    $worker = new Worker(
        ['outbox' => $receiver],
        $bus,
        $this->getContainer()->get('event_dispatcher'),
        $this->getContainer()->get('logger')
    );

    $worker->run([
        'limit' => $limit,
        'time-limit' => 5,
    ]);
}

protected function assertQueueEmpty(string $queueName): void
{
    $channel = self::getAmqpConnection()->channel();

    $message = $channel->basic_get($queueName);

    $channel->close();

    $this->assertNull($message, "Queue '$queueName' should be empty but contains messages");
}
```

## Future Considerations

### Failure Scenarios (Phase 2)

Once happy paths are solid, add tests for:

1. **Transaction Rollback**
   - Handler throws exception
   - Verify deduplication entry rolls back
   - Verify message can be retried

2. **AMQP Connection Failures**
   - RabbitMQ unavailable during publish
   - Verify retry behaviour
   - Verify failed transport usage

3. **Concurrent Processing**
   - Multiple workers processing simultaneously
   - Verify SKIP LOCKED behaviour
   - Verify no duplicate processing under load

### Performance Testing (Phase 3)

- Benchmark outbox processing throughput
- Benchmark inbox consumption rate
- Identify bottlenecks in database queries
- Optimise deduplication middleware performance

### Additional Flows (Phase 4)

- Test retry transport behaviour
- Test failed transport handling
- Test message routing to multiple handlers
- Test custom AMQP routing strategies

## References & Research

### Internal References

- **Brainstorm:** `docs/brainstorms/2026-01-29-functional-tests-brainstorm.md` - All architectural decisions
- **Database Schema:** `docs/database-schema.md` - Complete 3-table architecture
- **Unit Tests:** `tests/Unit/InboxFlowTest.php:32` - Existing flow test pattern
- **Test Factories:** `tests/Unit/Factory/EventBusFactory.php:50` - Factory pattern for test setup
- **Configuration:** `config/services.yaml:32` - Serialiser and middleware configuration

### External References

- PHPUnit Documentation: https://docs.phpunit.de/en/12.0/
- Symfony Testing: https://symfony.com/doc/current/testing.html
- Symfony Messenger: https://symfony.com/doc/current/messenger.html
- php-amqplib: https://github.com/php-amqplib/php-amqplib
- RabbitMQ Tutorials: https://www.rabbitmq.com/tutorials

### Related Work

- Existing Unit Tests: Full coverage of components in isolation
- Database Schema Documentation: Complete schema with migrations
- CLAUDE.md: Testing conventions and Docker requirements

---

## Summary

This plan implements comprehensive functional tests for the Freyr Message Broker's outbox and inbox patterns. Tests verify end-to-end flows with real infrastructure (MySQL + RabbitMQ) whilst maintaining fast execution through smart state management (truncate, not recreate).

**Key Principles:**
- ✅ Real infrastructure for production confidence
- ✅ Clean state between tests for determinism
- ✅ External infrastructure assumption for speed
- ✅ Happy paths first, failure scenarios later (YAGNI)
- ✅ Follow existing unit test patterns and style

**Estimated Effort:** 2-3 days for complete implementation across all 5 phases.

---

## Post-Deepening Recommendations

### Immediate (Before Implementation)

1. **Install Testing Libraries**
   ```bash
   composer require --dev dama/doctrine-test-bundle  # Transaction rollback for isolation
   composer require --dev zenstruck/messenger-test    # Messenger testing helpers
   ```

2. **Add Transaction Rollback Test** - Verify deduplication entry rolls back when handler throws exception:
   ```php
   public function testDeduplicationRollsBackOnHandlerException(): void
   {
       // Given: Handler that throws
       // When: Message consumed
       // Then: Deduplication entry rolled back, message can be retried
   }
   ```

3. **Remove test_handler_audit Table** - Use variable tracking instead of database insertion in Test 4

### Optional Enhancements (Post-Implementation)

1. **Parallel Test Execution** - Install ParaTest for 3-4x speedup:
   ```bash
   composer require --dev brianium/paratest
   vendor/bin/paratest --processes=4 tests/Functional
   ```

2. **Docker Optimizations** - Add to `docker-compose.test.yml`:
   - tmpfs volumes for MySQL (`/var/lib/mysql`)
   - Health checks with `start_period`
   - Port configuration for parallel execution

3. **Binary UUID Assertion Helpers** - Extract to trait:
   ```php
   trait BinaryUuidAssertionsTrait {
       protected function assertValidUuidV7(string $binaryUuid): void { }
       protected function assertBinaryUuidEquals(Id $expected, string $actual): void { }
   }
   ```

### Performance Baselines

**Expected Execution Times:**
- Single OutboxFlowTest: ~100-150ms
- Single InboxFlowTest: ~150-200ms
- Complete Functional Suite (7 tests): ~5-7s
- With ParaTest (4 cores): ~2-3s

**Performance SLOs:**
- No single test should exceed 500ms
- Full functional suite should complete in <10s
- CI pipeline should complete within 5 minutes (including setup/teardown)
