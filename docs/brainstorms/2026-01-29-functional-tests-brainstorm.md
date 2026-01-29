# Functional Tests for Message Broker - Brainstorm

**Date:** 2026-01-29
**Status:** Ready for Planning

## What We're Building

Comprehensive functional tests for Freyr Message Broker divided into two categories:

1. **Outbox Flow Tests** (`tests/Functional/OutboxFlowTest.php`)
   - Verify complete outbox pattern: domain event → database → AMQP publishing
   - Test transactional guarantees with real database
   - Verify message format (headers, body, stamps) in AMQP

2. **Inbox Flow Tests** (`tests/Functional/InboxFlowTest.php`)
   - Verify complete inbox pattern: AMQP → deduplication → handler execution
   - Test deduplication middleware with real database
   - Verify semantic name translation (order.placed → App\Message\OrderPlaced)

Both test suites will use **real infrastructure** (MySQL + RabbitMQ) for maximum production confidence.

## Why This Approach

### Infrastructure Management Decision

**Chosen Strategy:** Tests assume infrastructure exists externally (not managed by test code).

**Rationale:**
- Mirrors local development workflow (developers run `docker-compose up` once)
- Faster test execution - no container startup overhead per test run
- Simpler test code - focus on behavior, not infrastructure management
- CI/CD runs `docker-compose up -d` before test suite, keeps containers running

**State Management:**
- Database: Truncate tables between tests (not DROP/CREATE schema)
- AMQP: Purge queues between tests (not delete/recreate queues)
- Keeps infrastructure warm while ensuring clean state

### Test Scope Decision

**Initial Scope: Happy Paths Only**

**Coverage:**
- ✅ Outbox: Event published → stored in messenger_outbox → published to AMQP
- ✅ Inbox: Message consumed → deduplicated → handler executes → ACK
- ✅ Message format verification (correct headers, body structure, stamps)
- ✅ Transactional behavior (event saved atomically with deduplication entry)

**Deferred to Future:**
- ❌ Transaction rollback scenarios (handler failures)
- ❌ AMQP connection failures and retry behavior
- ❌ Concurrent processing and SKIP LOCKED behavior
- ❌ Performance/load testing

**Reasoning:** Start with proving core functionality works end-to-end. Failure scenarios can be layered on once happy paths are solid (YAGNI principle).

### Test Organization Decision

**Structure:** Separate test classes with shared base class

```
tests/Functional/
├── FunctionalTestCase.php      # Base: DB/AMQP cleanup, assertions
├── OutboxFlowTest.php          # Outbox pattern tests
└── InboxFlowTest.php           # Inbox pattern tests
```

**Benefits:**
- Clear separation: run outbox or inbox tests independently
- Shared infrastructure cleanup logic in base class
- Easy to add more functional test suites later
- Matches existing unit test organization pattern

## Key Decisions

### 1. Infrastructure Assumption Model

**Decision:** Tests assume MySQL and RabbitMQ are running, do not manage lifecycle.

**Implementation:**
- `FunctionalTestCase::setUp()` - Truncate database tables, purge AMQP queues
- CI: Run `docker-compose -f docker-compose.test.yml up -d` before PHPUnit
- Local: Developer runs infrastructure once, tests use it repeatedly

**Alternative Considered:** PHPUnit managing containers in `setUpBeforeClass()` - Rejected because too slow and unnecessary complexity.

### 2. Database Cleanup Strategy

**Decision:** Truncate tables between tests, not DROP/CREATE.

**Tables to Truncate:**
- `messenger_outbox`
- `message_broker_deduplication`
- `messenger_messages`

**Reasoning:**
- Faster than schema recreation
- Schema already exists from migrations
- Preserves indexes and constraints

### 3. AMQP Cleanup Strategy

**Decision:** Purge queues between tests using `queue_purge`.

**Queues to Purge:**
- `outbox` (if using AMQP for outbox consumption in tests)
- Test-specific queues created for inbox testing
- `failed` queue

**Reasoning:**
- Faster than deleting/recreating queues
- Preserves bindings and configuration
- Matches production behavior (queues persist, messages don't)

### 4. Test Data Fixtures

**Decision:** Use simple, inline fixtures in each test method (no shared fixture classes).

**Approach:**
```php
// Each test creates its own test message
$testEvent = new TestEvent(
    id: Id::new(),
    name: 'test-event',
    timestamp: CarbonImmutable::now()
);
```

**Reasoning:**
- Tests are self-contained and readable
- No hidden dependencies on fixture files
- Easy to customize per test scenario
- YAGNI - don't build fixture infrastructure until needed

### 5. Assertion Strategy

**Decision:** Assert at multiple layers for comprehensive verification.

**Layers:**
- **Database Layer:** Query tables directly to verify persistence
- **AMQP Layer:** Consume messages to verify format and headers
- **Handler Layer:** Verify handlers executed via test doubles or side effects

**Example:**
```php
// Assert in messenger_outbox table
$this->assertDatabaseHas('messenger_outbox', ['body' => /* ... */]);

// Assert message published to AMQP
$message = $this->consumeFromQueue('outbox');
$this->assertArrayHasKey('type', $message['headers']);
$this->assertEquals('test.event.sent', $message['headers']['type']);

// Assert handler was invoked
$this->assertTrue($this->testHandler->wasCalled());
```

### 6. Docker Compose Configuration

**Decision:** Create separate `docker-compose.test.yml` for test infrastructure.

**Services:**
```yaml
services:
  mysql_test:
    image: mysql:9.1
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: messenger_test
      MYSQL_USER: messenger
      MYSQL_PASSWORD: messenger
    ports:
      - "3307:3306"  # Different port from development

  rabbitmq_test:
    image: rabbitmq:4.0-management
    environment:
      RABBITMQ_DEFAULT_USER: guest
      RABBITMQ_DEFAULT_PASS: guest
    ports:
      - "5673:5672"   # Different port from development
      - "15673:15672" # Management UI
```

**Reasoning:**
- Isolated from development environment
- Can run tests while development is running
- CI/CD uses same configuration

## Open Questions

### Q1: Should tests create AMQP queues/exchanges or assume they exist?

**Context:** Production assumes queues/exchanges pre-exist (RabbitMQ admin creates them). Tests could either:
- (A) Create temporary test queues/exchanges programmatically
- (B) Assume queues exist and are configured in docker-compose

**Leaning toward:** (A) Create programmatically - tests are more self-contained, less setup required.

**Impact:** Planning phase should decide queue creation strategy.

---

### Q2: How to handle Symfony Messenger worker process for inbox tests?

**Context:** Inbox tests need to consume messages from AMQP. Options:
- (A) Run `messenger:consume` in background process from test
- (B) Manually consume messages using lower-level AMQP library
- (C) Use Symfony's `Worker` class directly in test

**Leaning toward:** (C) Use Worker class directly - most integrated with Symfony Messenger, no subprocess management.

**Impact:** Planning phase should specify exact consumption mechanism.

---

### Q3: Should tests verify message IDs are UUID v7?

**Context:** OutboxToAmqpBridge generates UUID v7 for MessageIdStamp. Should tests:
- (A) Just assert a UUID exists (any version)
- (B) Parse and verify it's a valid UUID v7 with correct timestamp ordering

**Leaning toward:** (A) for initial version - UUID existence is sufficient for functional testing. V7 validation is more of a unit test concern.

**Impact:** Low impact, can be decided during implementation.

---

## Success Criteria

### Outbox Flow Tests Pass When:
1. Domain event dispatched via MessageBus
2. Event stored in `messenger_outbox` table with correct serialization
3. OutboxToAmqpBridge processes from outbox
4. Message published to AMQP with:
   - `type` header = semantic name (`test.event.sent`)
   - `X-Message-Stamp-MessageIdStamp` header exists
   - Body contains business data (no messageId in payload)
5. Message can be consumed from AMQP and deserialized correctly

### Inbox Flow Tests Pass When:
1. Message published to AMQP queue with semantic name
2. InboxSerializer translates semantic name to PHP FQN
3. DeduplicationMiddleware checks message_broker_deduplication table
4. First message: Handler executes, deduplication entry created
5. Duplicate message: Handler NOT executed, message ACK'd
6. Database state reflects handler execution (e.g., record created)

### CI/CD Integration Works When:
1. `docker-compose -f docker-compose.test.yml up -d` starts infrastructure
2. `docker-compose run --rm php vendor/bin/phpunit tests/Functional/` passes
3. Tests can run multiple times without manual cleanup
4. Exit code 0 for success, non-zero for failures

## Next Steps

1. **Run `/workflows:plan`** to create detailed implementation plan
   - Will auto-detect this brainstorm and reference decisions
   - Will produce task breakdown for implementation

2. **Implementation Order (suggested):**
   - Step 1: Create `docker-compose.test.yml`
   - Step 2: Implement `FunctionalTestCase` base class
   - Step 3: Implement `OutboxFlowTest` (simpler, no deduplication)
   - Step 4: Implement `InboxFlowTest` (builds on outbox)
   - Step 5: Add CI/CD configuration

3. **Testing the Tests:**
   - Run locally to validate docker-compose setup
   - Add to CI/CD pipeline
   - Document how to run in README.md

---

**Brainstorm Status:** ✅ Complete - Ready for Planning Phase
