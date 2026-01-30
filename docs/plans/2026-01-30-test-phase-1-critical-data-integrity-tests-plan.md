---
title: Phase 1 - Critical Data Integrity Tests for Message Broker
type: test
date: 2026-01-30
---

# Phase 1: Critical Data Integrity Tests for Message Broker

## Overview

Implement comprehensive functional tests for critical data integrity scenarios covering handler exceptions, deduplication edge cases, and concurrent processing. These tests verify transactional guarantees, deduplication atomicity, and race condition handling to ensure the message broker prevents data loss and duplicate processing.

## Context

**Current Test Coverage**: Happy-path scenarios work well
- ✅ Outbox: Event dispatch → storage → AMQP publishing
- ✅ Inbox: AMQP consumption → deserialization → deduplication → handler
- ✅ Basic deduplication: First message vs duplicate

**Critical Gap**: Missing coverage for failure modes and edge cases that could lead to:
- ❌ Data loss (handler fails but message lost)
- ❌ Duplicate processing (deduplication bypassed)
- ❌ Inconsistent state (partial commits)
- ❌ Race conditions (concurrent workers)

**Brainstorm Document**: `docs/brainstorms/2026-01-30-edge-case-failure-mode-test-scenarios.md`

**Existing Infrastructure**: `tests/Functional/FunctionalTestCase.php` provides excellent foundation with helpers, connection pooling, and binary UUID handling.

**Plan Review**: This plan was reviewed by three specialized reviewers (DHH, Kieran, Simplicity). Following Kieran's meticulous best practices approach, incorporating HIGH PRIORITY fixes while removing only extreme edge cases.

**Key Changes from Review**:
- ✅ Reduced from 17 to 11 tests (removed hypothetical scenarios)
- ✅ Added Kieran's comprehensive assertions (exact counts, message data verification, cleanup state)
- ✅ Fixed AMQP exception swallowing (CRITICAL for inbox tests)
- ✅ Added defensive tearDown (prevents static state leakage)
- ✅ Strengthened all test assertions following Kieran's quality standards
- ❌ Removed: messageId in payload test (hypothetical)
- ❌ Removed: messageId reuse after cleanup (extremely unlikely with UUID v7)
- ❌ Removed: Database constraint violation test (requires complex fixture)
- ❌ Removed: Meta-test for middleware priority (not a functional test)
- ❌ Removed: Placeholder concurrent tests (deferred to Phase 4)

---

## Problem Statement

The message broker architecture relies on critical guarantees:

1. **Transactional Atomicity**: Deduplication entry and handler logic must commit/rollback together
2. **Exactly-Once Processing**: Messages processed exactly once at handler level (via deduplication)
3. **Concurrent Safety**: Multiple workers can consume from same queue without duplicate processing
4. **Retry Safety**: Failed messages can be retried without blocking via stale deduplication entries

**Without comprehensive tests**, production failures could occur:
- Handler throws exception → dedup entry remains → message blocked from retry ❌
- Concurrent workers → duplicate processing ❌
- Missing MessageIdStamp → system crashes ❌

---

## Proposed Solution

Implement **3 test suites** (11 test methods total) covering Phase 1 critical data integrity scenarios:

### Suite 1: Handler Exception & Rollback (3 test methods)
**File**: `tests/Functional/InboxTransactionRollbackTest.php`

Test transactional guarantee: dedup entry + handler logic commit/rollback atomically.

### Suite 2: Deduplication Edge Cases (6 test methods)
**File**: `tests/Functional/InboxDeduplicationEdgeCasesTest.php`

Test deduplication resilience: missing stamps, invalid UUIDs, malformed messages.

### Suite 3: Concurrent Processing (2 test methods)
**File**: `tests/Functional/InboxConcurrentProcessingTest.php`

Test basic concurrent scenarios: distinct message processing, duplicate detection.

**Removed (6 extreme edge cases):**
- ❌ `testMessageWithMessageIdInPayloadUsesStampOnly()` - Hypothetical scenario
- ❌ `testMessageIdReuseAfterCleanupIsProcessed()` - Extremely unlikely with UUID v7
- ❌ `testDatabaseConstraintViolationInHandler()` - Requires complex fixture
- ❌ `testDeduplicationMiddlewareRunsAfterDoctrineTransaction()` - Meta-test
- ❌ `testRaceConditionInDeduplicationInsert()` - Requires parallel infrastructure (defer to Phase 4)
- ❌ `testMessageRedeliveryDuringProcessingIsHandledSafely()` - Requires slow handler (defer to Phase 4)

---

## Technical Approach

### Architecture Context

**Middleware Execution Order** (Critical for Transaction Tests):
```
1. doctrine_transaction (priority 0)     → STARTS TRANSACTION
2. DeduplicationMiddleware (priority -10) → RUNS INSIDE TRANSACTION
3. Handler execution
4. COMMIT (if success) or ROLLBACK (if exception)
```

**Deduplication Pattern**:
```php
// DeduplicationDbalStore::isDuplicate()
try {
    $this->connection->insert('message_broker_deduplication', [...]);
    return false; // Insert succeeded → not duplicate
} catch (UniqueConstraintViolationException $e) {
    return true; // Insert failed → duplicate
}
```

**Key Guarantee**: If handler throws exception:
- Transaction rolls back
- Deduplication entry deleted (part of transaction)
- Message remains in queue (NACK'd)
- Message can be retried

---

## Implementation Plan

### Phase 1A: Test Fixtures & Infrastructure

Create reusable test fixtures and helper methods needed across all test suites.

#### 1. Create ThrowingTestEventHandler

**File**: `tests/Functional/Fixtures/ThrowingTestEventHandler.php`

Handler that can be configured to throw exceptions on demand.

```php
<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Test handler that can throw exceptions on demand.
 *
 * Used for testing transaction rollback scenarios.
 */
#[AsMessageHandler]
final class ThrowingTestEventHandler
{
    private static int $invocationCount = 0;
    private static ?TestEvent $lastMessage = null;
    private static ?\Throwable $exceptionToThrow = null;

    public function __invoke(TestEvent $message): void
    {
        self::$invocationCount++;
        self::$lastMessage = $message;

        if (self::$exceptionToThrow !== null) {
            $exception = self::$exceptionToThrow;
            self::$exceptionToThrow = null; // Reset after throwing
            throw $exception;
        }
    }

    public static function throwOnNextInvocation(\Throwable $exception): void
    {
        self::$exceptionToThrow = $exception;
    }

    public static function getInvocationCount(): int
    {
        return self::$invocationCount;
    }

    public static function getLastMessage(): ?TestEvent
    {
        return self::$lastMessage;
    }

    public static function reset(): void
    {
        self::$invocationCount = 0;
        self::$lastMessage = null;
        self::$exceptionToThrow = null;
    }
}
```

**Configuration**: Add to `tests/Functional/config/test.yaml`
```yaml
services:
    Freyr\MessageBroker\Tests\Functional\Fixtures\ThrowingTestEventHandler:
        autoconfigure: true
```

**Update FunctionalTestCase::resetHandlers()**:
```php
protected function resetHandlers(): void
{
    TestEventHandler::reset();
    OrderPlacedHandler::reset();
    ThrowingTestEventHandler::reset(); // Add this
}
```

**Add Defensive tearDown (Kieran's HIGH PRIORITY fix)**:
```php
protected function tearDown(): void
{
    // Defensive: Always reset handlers even if test failed
    // Prevents static state leakage between tests
    $this->resetHandlers();
    parent::tearDown();
}
```

---

#### 2. Add Helper Methods to FunctionalTestCase

**Method**: `assertNoDeduplicationEntryExists(string $messageId): void`

```php
/**
 * Assert that no deduplication entry exists for given message ID.
 *
 * Used to verify transaction rollback after handler failure.
 */
protected function assertNoDeduplicationEntryExists(string $messageId): void
{
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

    $messageIdHex = strtoupper(str_replace('-', '', $messageId));

    $result = $connection->fetchOne(
        'SELECT COUNT(*) FROM message_broker_deduplication WHERE HEX(message_id) = ?',
        [$messageIdHex]
    );

    $this->assertEquals(
        0,
        (int) $result,
        sprintf('Expected no deduplication entry for message ID %s, but found one', $messageId)
    );
}
```

**Method**: `assertMessageInFailedTransport(string $messageClass): array`

```php
/**
 * Assert that a message exists in the failed transport.
 *
 * @return array{body: array, headers: array}
 */
protected function assertMessageInFailedTransport(string $messageClass): array
{
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

    $result = $connection->fetchAssociative(
        "SELECT body, headers FROM messenger_messages WHERE queue_name = 'failed' ORDER BY id DESC LIMIT 1"
    );

    $this->assertIsArray($result, 'Expected message in failed transport, but failed transport is empty');

    $headers = json_decode($result['headers'], true);
    $this->assertIsArray($headers);
    $this->assertArrayHasKey('X-Message-Class', $headers);
    $this->assertEquals($messageClass, $headers['X-Message-Class'][0]);

    $body = json_decode($result['body'], true);
    $this->assertIsArray($body);

    return [
        'body' => $body,
        'headers' => $headers,
    ];
}
```

**Method**: `getTableRowCount(string $table): int`

```php
/**
 * Get row count for a table (helper for quick assertions).
 */
protected function getTableRowCount(string $table): int
{
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
    return (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table}");
}
```

**Fix AMQP Exception Swallowing (Kieran's CRITICAL fix)**:

Update existing `setupAmqp()` method in FunctionalTestCase to fail inbox tests if AMQP is unavailable:

```php
private function setupAmqp(): void
{
    try {
        $connection = $this->getAmqpConnection();
        $channel = $connection->channel();

        // Declare exchange and queues...

    } catch (\Exception $e) {
        // CRITICAL: Inbox tests MUST fail if AMQP is unavailable
        // Outbox-only tests can continue (they don't need AMQP)
        if (str_contains(static::class, 'Inbox')) {
            throw new \RuntimeException(
                'AMQP setup failed for inbox test. RabbitMQ must be running: ' . $e->getMessage(),
                previous: $e
            );
        }

        // Outbox tests can continue without AMQP (they only test database storage)
        // Log the warning but don't fail
        error_log("AMQP unavailable for outbox test: " . $e->getMessage());
    }
}
```

---

#### 3. Create Malformed Message Helper

**Method**: `publishMalformedAmqpMessage()`

Add to FunctionalTestCase for testing edge cases.

```php
/**
 * Publish a malformed AMQP message for testing error handling.
 *
 * @param string $queue Queue name
 * @param array $options Options: 'missingType', 'missingMessageId', 'invalidUuid', 'invalidJson'
 */
protected function publishMalformedAmqpMessage(string $queue, array $options = []): void
{
    $connection = $this->getAmqpConnection();
    $channel = $connection->channel();

    $headers = [];
    $body = '{"id": "01234567-89ab-cdef-0123-456789abcdef", "name": "test", "timestamp": "2026-01-30T12:00:00+00:00"}';

    // Apply malformation options
    if (!in_array('missingType', $options)) {
        $headers['type'] = 'test.event.sent';
    }

    if (!in_array('missingMessageId', $options)) {
        if (in_array('invalidUuid', $options)) {
            $headers['X-Message-Stamp-MessageIdStamp'] = json_encode([['messageId' => 'not-a-uuid']]);
        } else {
            $headers['X-Message-Stamp-MessageIdStamp'] = json_encode([['messageId' => '01234567-89ab-cdef-0123-456789abcdef']]);
        }
    }

    if (in_array('invalidJson', $options)) {
        $body = '{invalid json';
    }

    $amqpMessage = new AMQPMessage($body, [
        'content_type' => 'application/json',
        'application_headers' => new AMQPTable($headers),
    ]);

    $channel->basic_publish($amqpMessage, '', $queue);
}
```

---

### Phase 1B: Suite 1 - Handler Exception & Rollback Tests

**File**: `tests/Functional/InboxTransactionRollbackTest.php`

#### Test 1: testHandlerExceptionRollsBackDeduplicationEntry()

**Scenario**: Handler throws RuntimeException on first processing attempt.

**Expected**: Deduplication entry NOT committed (transaction rolled back), message remains in queue.

```php
public function testHandlerExceptionRollsBackDeduplicationEntry(): void
{
    // Given: A message with MessageIdStamp
    $messageId = Id::new()->__toString();
    $testEvent = new TestEvent(
        id: Id::new(),
        name: 'will-fail',
        timestamp: CarbonImmutable::now()
    );

    // Configure handler to throw exception
    ThrowingTestEventHandler::throwOnNextInvocation(
        new \RuntimeException('Handler failure simulation')
    );

    // Publish to AMQP with MessageIdStamp
    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
    ], [
        'id' => $testEvent->id->__toString(),
        'name' => $testEvent->name,
        'timestamp' => $testEvent->timestamp->toIso8601String(),
    ]);

    // When: Worker consumes message (handler throws)
    try {
        $this->consumeFromInbox(limit: 1);
    } catch (\Exception $e) {
        // Expected: Worker will throw exception
    }

    // Then: Handler was invoked exactly once
    $this->assertEquals(1, ThrowingTestEventHandler::getInvocationCount(),
        'Handler should be invoked once despite exception');

    // And: Handler received correct message data (verify payload integrity)
    $lastMessage = ThrowingTestEventHandler::getLastMessage();
    $this->assertNotNull($lastMessage);
    $this->assertSame('will-fail', $lastMessage->name);

    // And: NO deduplication entry exists (transaction rolled back)
    $this->assertNoDeduplicationEntryExists($messageId);

    // And: Message NOT in failed transport (should remain in queue for retry, not moved to failed)
    $this->assertEquals(0, $this->getTableRowCount('messenger_messages'),
        'Message should remain in queue for retry, not moved to failed transport');

    // And: All other tables clean (verify complete rollback)
    $this->assertEquals(0, $this->getTableRowCount('messenger_outbox'));
    $this->assertEquals(0, $this->getDeduplicationEntryCount());
}
```

---

#### Test 2: testHandlerSucceedsAfterRetry()

**Scenario**: Handler throws on first attempt, succeeds on retry.

**Expected**: Deduplication entry created only after success, handler invoked twice total.

```php
public function testHandlerSucceedsAfterRetry(): void
{
    // Given: A message that will be retried
    $messageId = Id::new()->__toString();
    $testEvent = new TestEvent(
        id: Id::new(),
        name: 'retry-success',
        timestamp: CarbonImmutable::now()
    );

    // First attempt: Handler throws
    ThrowingTestEventHandler::throwOnNextInvocation(
        new \RuntimeException('First attempt fails')
    );

    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
    ], [
        'id' => $testEvent->id->__toString(),
        'name' => $testEvent->name,
        'timestamp' => $testEvent->timestamp->toIso8601String(),
    ]);

    // When: First attempt (fails)
    try {
        $this->consumeFromInbox(limit: 1);
    } catch (\Exception $e) {
        // Expected
    }

    // Then: Handler invoked once
    $this->assertEquals(1, ThrowingTestEventHandler::getInvocationCount());

    // And: No deduplication entry (rolled back)
    $this->assertNoDeduplicationEntryExists($messageId);

    // Republish same message (simulating retry)
    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
    ], [
        'id' => $testEvent->id->__toString(),
        'name' => $testEvent->name,
        'timestamp' => $testEvent->timestamp->toIso8601String(),
    ]);

    // When: Second attempt (succeeds - no exception configured)
    $this->consumeFromInbox(limit: 1);

    // Then: Handler invoked exactly twice total (once failed, once succeeded)
    $this->assertEquals(2, ThrowingTestEventHandler::getInvocationCount());

    // And: Last message processed has correct data (verify second attempt)
    $lastMessage = ThrowingTestEventHandler::getLastMessage();
    $this->assertSame('retry-success', $lastMessage->name);

    // And: Deduplication entry NOW exists (committed with successful handler execution)
    $this->assertDeduplicationEntryExists($messageId);

    // And: No messages in outbox or failed transport (cleanup verification)
    $this->assertEquals(0, $this->getTableRowCount('messenger_outbox'));
    $this->assertEquals(0, $this->getTableRowCount('messenger_messages'),
        'No failed messages after successful retry');

    // And: Queue is empty (message consumed and ACK'd)
    $this->assertQueueEmpty('test_inbox');
}
```

---

#### Test 3: testMultipleHandlerExceptionsInSequence()

**Scenario**: Handler fails 3 times in a row, succeeds on 4th attempt.

**Expected**: No deduplication entry until success, message processed correctly after retries.

```php
public function testMultipleHandlerExceptionsInSequence(): void
{
    // Given: A message that will fail 3 times
    $messageId = Id::new()->__toString();
    $testEvent = new TestEvent(
        id: Id::new(),
        name: 'multiple-retries',
        timestamp: CarbonImmutable::now()
    );

    // Attempt 1-3: Failures
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        ThrowingTestEventHandler::throwOnNextInvocation(
            new \RuntimeException("Attempt {$attempt} fails")
        );

        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
        ], [
            'id' => $testEvent->id->__toString(),
            'name' => $testEvent->name,
            'timestamp' => $testEvent->timestamp->toIso8601String(),
        ]);

        try {
            $this->consumeFromInbox(limit: 1);
        } catch (\Exception $e) {
            // Expected
        }

        // Verify no deduplication entry after each failure
        $this->assertNoDeduplicationEntryExists($messageId);
    }

    // Attempt 4: Success
    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
    ], [
        'id' => $testEvent->id->__toString(),
        'name' => $testEvent->name,
        'timestamp' => $testEvent->timestamp->toIso8601String(),
    ]);

    $this->consumeFromInbox(limit: 1);

    // Then: Handler invoked exactly 4 times total (3 failures + 1 success)
    $this->assertEquals(4, ThrowingTestEventHandler::getInvocationCount(),
        '4 invocations expected: 3 failures + 1 success');

    // And: Last message processed correctly (verify data integrity)
    $lastMessage = ThrowingTestEventHandler::getLastMessage();
    $this->assertSame('multiple-retries', $lastMessage->name);

    // And: Deduplication entry exists after success
    $this->assertDeduplicationEntryExists($messageId);

    // And: Cleanup verification (no stale data)
    $this->assertEquals(1, $this->getDeduplicationEntryCount(),
        'Exactly one deduplication entry after all retries');
    $this->assertEquals(0, $this->getTableRowCount('messenger_messages'),
        'No failed messages after successful retry');
}
```

---

### Phase 1C: Suite 2 - Deduplication Edge Cases

**File**: `tests/Functional/InboxDeduplicationEdgeCasesTest.php`

#### Test 1: testMessageWithoutMessageIdStampIsRejected()

**Scenario**: AMQP message missing X-Message-Stamp-MessageIdStamp header.

**Expected**: Message rejected, moved to failed transport, handler not invoked.

```php
public function testMessageWithoutMessageIdStampIsRejected(): void
{
    // Given: A message without MessageIdStamp header
    $this->publishMalformedAmqpMessage('test_inbox', ['missingMessageId']);

    // When: Worker attempts to consume
    try {
        $this->consumeFromInbox(limit: 1);
    } catch (\Exception $e) {
        // May throw during deserialization
    }

    // Then: Handler was NOT invoked (exactly 0, not "at least 0")
    $this->assertEquals(0, TestEventHandler::getInvocationCount(),
        'Handler should not be invoked for message without MessageIdStamp');

    // And: Message is SPECIFICALLY in FAILED transport (not retry queue)
    $failedMessage = $this->assertMessageInFailedTransport(TestEvent::class);

    // And: Error metadata indicates missing MessageIdStamp
    $this->assertArrayHasKey('X-Message-Error', $failedMessage['headers']);
    $this->assertStringContainsString('MessageIdStamp', $failedMessage['headers']['X-Message-Error'][0],
        'Error should mention missing MessageIdStamp');

    // And: No deduplication entry created (message never reached middleware)
    $this->assertEquals(0, $this->getDeduplicationEntryCount(),
        'No dedup entry should exist for rejected message');
}
```

---

#### Test 2: testMessageWithInvalidUuidInMessageIdStampIsRejected()

**Scenario**: MessageIdStamp contains non-UUID value (e.g., "not-a-uuid").

**Expected**: Message rejected with validation error, moved to failed transport.

```php
public function testMessageWithInvalidUuidInMessageIdStampIsRejected(): void
{
    // Given: A message with invalid UUID in MessageIdStamp
    $this->publishMalformedAmqpMessage('test_inbox', ['invalidUuid']);

    // When: Worker attempts to consume
    try {
        $this->consumeFromInbox(limit: 1);
    } catch (\Exception $e) {
        // Expected: Validation exception during deserialization
    }

    // Then: Handler was NOT invoked
    $this->assertEquals(0, TestEventHandler::getInvocationCount(),
        'Handler should not be invoked for message with invalid UUID');

    // And: Message in failed transport with error details
    $failedMessage = $this->assertMessageInFailedTransport(TestEvent::class);

    // And: Error indicates UUID validation failure
    $this->assertArrayHasKey('X-Message-Error', $failedMessage['headers']);
    $errorMessage = $failedMessage['headers']['X-Message-Error'][0];
    $this->assertTrue(
        str_contains($errorMessage, 'UUID') || str_contains($errorMessage, 'invalid'),
        'Error should mention UUID validation failure'
    );

    // And: No deduplication entry (validation failed before middleware)
    $this->assertEquals(0, $this->getDeduplicationEntryCount());
}
```

---

#### Test 3: testInvalidJsonBodyIsRejected()

**Scenario**: Message body contains malformed JSON.

**Expected**: Serialization exception, message moved to failed transport.

```php
public function testInvalidJsonBodyIsRejected(): void
{
    // Given: A message with invalid JSON body
    $this->publishMalformedAmqpMessage('test_inbox', ['invalidJson']);

    // When: Worker attempts to consume
    try {
        $this->consumeFromInbox(limit: 1);
    } catch (\Exception $e) {
        // Expected: SerializationException during message deserialization
    }

    // Then: Handler was NOT invoked
    $this->assertEquals(0, TestEventHandler::getInvocationCount(),
        'Handler should not be invoked for message with malformed JSON');

    // And: Message in failed transport with JSON parse error
    $this->assertEquals(1, $this->getTableRowCount('messenger_messages'),
        'Exactly one message in failed transport');

    // Verify it's in the FAILED queue specifically
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
    $queueName = $connection->fetchOne(
        "SELECT queue_name FROM messenger_messages WHERE queue_name = 'failed' LIMIT 1"
    );
    $this->assertEquals('failed', $queueName, 'Message must be in failed queue, not retry queue');

    // And: No deduplication entry (deserialization failed before dedup middleware)
    $this->assertEquals(0, $this->getDeduplicationEntryCount());
}
```

---

#### Test 4: testMissingTypeHeaderIsRejected()

**Scenario**: AMQP message without semantic `type` header.

**Expected**: Cannot route to handler, message rejected to failed transport.

```php
public function testMissingTypeHeaderIsRejected(): void
{
    // Given: A message without type header
    $this->publishMalformedAmqpMessage('test_inbox', ['missingType']);

    // When: Worker attempts to consume
    try {
        $this->consumeFromInbox(limit: 1);
    } catch (\Exception $e) {
        // Expected: Cannot translate semantic name to FQN
    }

    // Then: Handler NOT invoked
    $this->assertEquals(0, TestEventHandler::getInvocationCount(),
        'Handler cannot be invoked without type header');

    // And: Message in failed transport
    $this->assertEquals(1, $this->getTableRowCount('messenger_messages'));

    // And: Error indicates missing type header
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
    $headers = $connection->fetchOne(
        "SELECT headers FROM messenger_messages WHERE queue_name = 'failed' LIMIT 1"
    );
    $this->assertNotFalse($headers);
    $this->assertStringContainsString('type', strtolower($headers),
        'Error should mention missing type header');
}
```

---

#### Test 5: testUnmappedTypeHeaderIsRejected()

**Scenario**: `type` header contains value not in `message_types` config (e.g., `unknown.event.name`).

**Expected**: Cannot translate to FQN, clear error, failed transport.

```php
public function testUnmappedTypeHeaderIsRejected(): void
{
    // Given: A message with unmapped type header
    $this->publishToAmqp('test_inbox', [
        'type' => 'unknown.event.name', // Not in message_types config
        'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => Id::new()->__toString()]]),
    ], [
        'id' => Id::new()->__toString(),
        'name' => 'unmapped-test',
        'timestamp' => CarbonImmutable::now()->toIso8601String(),
    ]);

    // When: Worker attempts to consume
    try {
        $this->consumeFromInbox(limit: 1);
    } catch (\Exception $e) {
        // Expected: InboxSerializer cannot map semantic name to FQN
    }

    // Then: Handler NOT invoked
    $this->assertEquals(0, TestEventHandler::getInvocationCount());

    // And: Message in failed transport with clear error
    $this->assertEquals(1, $this->getTableRowCount('messenger_messages'));

    // Verify error message clarity
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
    $result = $connection->fetchAssociative(
        "SELECT headers FROM messenger_messages WHERE queue_name = 'failed' LIMIT 1"
    );
    $this->assertIsArray($result);
    // Error should mention the unmapped type
    $this->assertStringContainsString('unknown.event.name', $result['headers']);
}
```

---

#### Test 6: testDuplicateMessageDuringFirstProcessingIsDetected()

**Scenario**: Same messageId published twice before first is processed.

**Expected**: First processes normally, second detects duplicate.

**Note**: This is a sequential test (not true concurrent), but verifies dedup logic works.

```php
public function testDuplicateMessageDuringFirstProcessingIsDetected(): void
{
    // Given: Same messageId published twice
    $messageId = Id::new()->__toString();

    for ($i = 1; $i <= 2; $i++) {
        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
        ], [
            'id' => Id::new()->__toString(),
            'name' => "duplicate-test-{$i}",
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    // When: Worker consumes both messages
    $this->consumeFromInbox(limit: 2);

    // Then: Handler invoked EXACTLY once (duplicate skipped)
    $this->assertEquals(1, TestEventHandler::getInvocationCount(),
        'Handler must be invoked exactly once for duplicate messages');

    // And: EXACTLY one deduplication entry
    $this->assertEquals(1, $this->getDeduplicationEntryCount(),
        'Exactly one dedup entry - duplicate not inserted');

    // And: Both messages ACK'd (queue empty)
    $this->assertQueueEmpty('test_inbox');

    // And: No failed messages (duplicate is not an error)
    $this->assertEquals(0, $this->getTableRowCount('messenger_messages'));
}
```

---

### Phase 1D: Suite 3 - Concurrent Processing (Basic)

**File**: `tests/Functional/InboxConcurrentProcessingTest.php`

**Note**: Concurrent tests are more complex and may require Symfony Process component. Start with simpler scenarios, defer advanced parallel execution to future iteration.

#### Test 1: testTwoWorkersProcessDistinctMessages()

**Scenario**: Publish 10 unique messages, verify all processed exactly once.

**Expected**: All 10 handlers invoked, 10 deduplication entries, no duplicates.

**Note**: This test doesn't require true parallelism - sequential processing with distinct messages is sufficient.

```php
public function testTwoWorkersProcessDistinctMessages(): void
{
    // Given: 10 unique messages published to queue
    $messageIds = [];
    for ($i = 1; $i <= 10; $i++) {
        $messageId = Id::new()->__toString();
        $messageIds[] = $messageId;

        $this->publishToAmqp('test_inbox', [
            'type' => 'test.event.sent',
            'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
        ], [
            'id' => Id::new()->__toString(),
            'name' => "message-{$i}",
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    // When: Worker consumes all messages
    $this->consumeFromInbox(limit: 10);

    // Then: All 10 handlers invoked exactly
    $this->assertEquals(10, TestEventHandler::getInvocationCount(),
        'All 10 distinct messages should be processed');

    // And: Exactly 10 UNIQUE deduplication entries (no duplicates)
    $this->assertEquals(10, $this->getDeduplicationEntryCount(),
        'Should have exactly 10 dedup entries for 10 distinct messages');

    // And: Each messageId has exactly one deduplication entry
    foreach ($messageIds as $messageId) {
        $this->assertDeduplicationEntryExists($messageId);
    }

    // And: No failed messages (all processing succeeded)
    $this->assertEquals(0, $this->getTableRowCount('messenger_messages'),
        'No messages should fail during normal processing');

    // And: Queue is empty (all consumed and ACK'd)
    $this->assertQueueEmpty('test_inbox');
}
```

---

#### Test 2: testDuplicateMessageIsSkippedBySecondWorker()

**Scenario**: Process message with Worker 1, republish same messageId, process with Worker 2.

**Expected**: Handler invoked once, duplicate detected by second worker.

```php
public function testDuplicateMessageIsSkippedBySecondWorker(): void
{
    // Given: A message processed by first worker
    $messageId = Id::new()->__toString();
    $testEvent = new TestEvent(
        id: Id::new(),
        name: 'first-worker',
        timestamp: CarbonImmutable::now()
    );

    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
    ], [
        'id' => $testEvent->id->__toString(),
        'name' => $testEvent->name,
        'timestamp' => $testEvent->timestamp->toIso8601String(),
    ]);

    // When: First worker processes
    $this->consumeFromInbox(limit: 1);

    // Then: Handler invoked once
    $this->assertEquals(1, TestEventHandler::getInvocationCount());
    $this->assertDeduplicationEntryExists($messageId);

    // When: Same message republished (simulating redelivery)
    $this->publishToAmqp('test_inbox', [
        'type' => 'test.event.sent',
        'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
    ], [
        'id' => $testEvent->id->__toString(),
        'name' => $testEvent->name,
        'timestamp' => $testEvent->timestamp->toIso8601String(),
    ]);

    // When: Second worker processes (simulating different worker)
    $this->consumeFromInbox(limit: 1);

    // Then: Handler still invoked only once (duplicate skipped)
    $this->assertEquals(1, TestEventHandler::getInvocationCount(),
        'Handler must be invoked exactly once despite redelivery');

    // And: CRITICAL - EXACTLY one deduplication entry (not zero, not two)
    $dedupCount = $this->getDeduplicationEntryCount();
    $this->assertEquals(1, $dedupCount,
        "Expected exactly 1 dedup entry, found {$dedupCount}. Duplicate processing detected.");

    // And: Verify the specific messageId has deduplication entry
    $this->assertDeduplicationEntryExists($messageId);

    // And: Queue is empty (second message was ACK'd despite being duplicate)
    $this->assertQueueEmpty('test_inbox');

    // And: No failed messages (duplicate is not an error condition)
    $this->assertEquals(0, $this->getTableRowCount('messenger_messages'));
}
```

---

## Acceptance Criteria

### Functional Requirements

#### Suite 1: Handler Exception & Rollback (3 tests)
- [ ] Handler exception rolls back deduplication entry (transaction atomicity verified)
- [ ] Handler succeeds after retry, deduplication entry committed atomically
- [ ] Multiple handler exceptions in sequence (3 failures + 1 success)

#### Suite 2: Deduplication Edge Cases (6 tests)
- [ ] Message without MessageIdStamp rejected, moved to failed transport
- [ ] Message with invalid UUID rejected with clear error message
- [ ] Invalid JSON body rejected with serialization error
- [ ] Missing `type` header rejected (cannot route to handler)
- [ ] Unmapped `type` header rejected with clear error
- [ ] Duplicate message during first processing detected (sequential test)

#### Suite 3: Concurrent Processing (2 tests)
- [ ] Multiple distinct messages (10) processed exactly once
- [ ] Duplicate message skipped by second worker (sequential simulation)

### Non-Functional Requirements

- [ ] All tests follow existing patterns (Given-When-Then structure)
- [ ] Tests execute in <30 seconds total (Suite 1 + Suite 2 + Suite 3)
- [ ] No flaky tests (deterministic outcomes)
- [ ] Clear failure messages (meaningful assertions)
- [ ] Test isolation (no interdependencies between tests)

### Quality Gates

- [ ] All new tests pass on first run
- [ ] Helper methods added are reusable across multiple tests
- [ ] Test fixtures follow existing conventions (static tracking)
- [ ] Configuration updated (test.yaml, FunctionalTestCase)
- [ ] Code review approval (verify transactional guarantee testing is correct)

---

## Dependencies & Risks

### Prerequisites

- ✅ FunctionalTestCase base class (exists)
- ✅ Docker compose infrastructure (MySQL, RabbitMQ)
- ✅ Existing test fixtures (TestEvent, TestEventHandler)
- ✅ Binary UUID handling helpers (assertDeduplicationEntryExists)

### Dependencies

- **ThrowingTestEventHandler** fixture (must create)
- **Helper methods** (assertNoDeduplicationEntryExists, assertMessageInFailedTransport)
- **Malformed message helpers** (publishMalformedAmqpMessage)
- (Optional) **Symfony Process component** for parallel worker tests

### Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Transaction rollback not testable | HIGH | Use database inspection to verify dedup entry absence |
| Concurrent tests flaky | MEDIUM | Start with sequential tests, defer true parallelism to iteration 2 |
| Failed transport inspection complex | LOW | Add helper method for failed message assertions |
| Test execution slow (>30s) | LOW | Use connection pooling (already implemented), limit parallel tests |

---

## Implementation Phases

### Iteration 1: Core Infrastructure + Suite 1 (Est. 3-4 hours)

**Tasks**:
1. Create `ThrowingTestEventHandler` fixture
2. Add helper methods to `FunctionalTestCase` (with Kieran's fixes)
   - `assertNoDeduplicationEntryExists()`
   - `assertMessageInFailedTransport()`
   - `getTableRowCount()`
   - `publishMalformedAmqpMessage()`
   - Add defensive `tearDown()`
   - Fix AMQP exception swallowing
3. Implement Suite 1 (3 tests) - Handler Exception & Rollback
4. Apply Kieran's comprehensive assertions to all tests
5. Verify all Suite 1 tests pass

**Deliverables**:
- `tests/Functional/Fixtures/ThrowingTestEventHandler.php`
- Updated `tests/Functional/FunctionalTestCase.php` (4 helper methods + tearDown + AMQP fix)
- `tests/Functional/InboxTransactionRollbackTest.php` (3 test methods)

---

### Iteration 2: Suite 2 - Deduplication Edge Cases (Est. 3-4 hours)

**Tasks**:
1. Implement Suite 2 (6 tests) - Deduplication Edge Cases
2. Apply Kieran's comprehensive assertions (exact counts, explicit checks, cleanup verification)
3. Verify all Suite 2 tests pass

**Deliverables**:
- `tests/Functional/InboxDeduplicationEdgeCasesTest.php` (6 test methods)

---

### Iteration 3: Suite 3 - Concurrent Processing (Basic) (Est. 2 hours)

**Tasks**:
1. Implement Suite 3 (2 tests) - Basic concurrent scenarios (sequential simulation)
2. Apply Kieran's CRITICAL missing assertion (exactly one dedup entry)
3. Verify all Suite 3 tests pass

**Deliverables**:
- `tests/Functional/InboxConcurrentProcessingTest.php` (2 test methods)

---

### Iteration 4: Documentation & Review (Est. 1 hour)

**Tasks**:
1. Update `CLAUDE.md` with new test fixtures and patterns
2. Run full test suite to verify no regressions
3. Code review: verify transactional guarantee testing is correct
4. Optional: Run `/workflows:compound` to document learnings

**Deliverables**:
- Updated `CLAUDE.md` documentation
- Test execution report (all green)
- Optional: `docs/solutions/testing/phase-1-critical-data-integrity-tests.md`

---

## Success Metrics

**Coverage Goals**:
- 🎯 Handler exception scenarios: 100% covered (3 test methods)
- 🎯 Deduplication edge cases: 100% covered (6 test methods)
- 🎯 Concurrent processing (basic): 100% covered (2 test methods)
- 🎯 Total: 11 test methods across 3 test files

**Kieran's Quality Standards Applied**:
- ✅ Comprehensive assertions (exact counts, message data verification, cleanup state)
- ✅ Explicit failed transport checks (queue_name='failed')
- ✅ Defensive tearDown (prevents static state leakage)
- ✅ AMQP exception handling fixed (inbox tests fail if AMQP unavailable)
- ✅ All helper methods verified for correctness (binary UUID handling)

**Quality Metrics**:
- Test execution time: <30 seconds total
- Test success rate: 100% (no flaky tests)
- Code coverage: 90%+ for critical paths (DeduplicationMiddleware, handlers)

**Documentation**:
- All new fixtures documented in `CLAUDE.md`
- Helper methods documented with PHPDoc
- Test patterns documented for future contributors

---

## Files to Create/Modify

### New Files (3 files)

1. `tests/Functional/Fixtures/ThrowingTestEventHandler.php` (~50 lines)
2. `tests/Functional/InboxTransactionRollbackTest.php` (~150 lines, 3 tests)
3. `tests/Functional/InboxDeduplicationEdgeCasesTest.php` (~300 lines, 6 tests)
4. `tests/Functional/InboxConcurrentProcessingTest.php` (~100 lines, 2 tests)

**Total**: ~600 lines of test code (down from ~600+ in original plan)

### Modified Files (3 files)

1. `tests/Functional/FunctionalTestCase.php`
   - Add 4 helper methods (~150 lines)
   - Add defensive tearDown (~10 lines)
   - Fix AMQP exception swallowing (~15 lines)
   - **Total additions**: ~175 lines

2. `tests/Functional/config/test.yaml`
   - Add ThrowingTestEventHandler service registration (~5 lines)

3. `CLAUDE.md`
   - Update with new test patterns and Kieran's quality standards
   - Document helper methods and fixtures (~50 lines)

---

## References & Research

### Internal References

- Brainstorm document: `docs/brainstorms/2026-01-30-edge-case-failure-mode-test-scenarios.md`
- Existing test infrastructure: `tests/Functional/FunctionalTestCase.php:298`
- Test fixtures: `tests/Functional/Fixtures/` (TestEvent, TestEventHandler, OrderPlaced)
- Configuration: `tests/Functional/config/test.yaml`

### Documented Learnings

- Critical middleware priority: `docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md`
- Deduplication architecture: `docs/inbox-deduplication.md`
- Binary UUID handling: `docs/solutions/database-issues/migration-schema-mismatch-ci-vs-local.md`

### Key Architectural Insights

1. **Middleware Priority Critical**: DeduplicationMiddleware MUST run with priority -10 (after doctrine_transaction) to ensure atomicity
2. **Transaction Rollback Pattern**: If handler throws, dedup entry + handler changes roll back together
3. **Binary UUID Assertions**: Use HEX comparison for binary UUID columns
4. **Safety Check Pattern**: Database name must contain `_test` to prevent production testing

---

## Next Steps After Phase 1

Once Phase 1 is complete and all critical data integrity tests are passing:

1. **Phase 2: Error Handling & Recovery** (10-15 test methods)
   - Serialization errors (invalid JSON, missing properties, type mismatches)
   - Connection failures (AMQP down, database timeout)
   - Failed message recovery (failed transport inspection, retry after fix)

2. **Phase 3: Validation & Security** (10-12 test methods)
   - Input validation (SQL injection attempts, XSS payloads)
   - Resource limits (oversized payloads, deeply nested JSON)
   - Edge cases (empty body, null values, special numeric values)

3. **Phase 4: Advanced Scenarios** (8-10 test methods)
   - Custom routing attributes (#[MessengerTransport], #[AmqpRoutingKey])
   - Performance/stress testing (message flood, worker timeout)
   - Custom routing strategies

4. **Optional: Integration Test Suite**
   - True parallel worker execution (Symfony Process component)
   - Docker container manipulation (network partition, service restart)
   - End-to-end scenarios (outbox → AMQP → inbox)

---

## Kieran's Quality Standards Applied

This plan incorporates Kieran's meticulous best practices for comprehensive test coverage:

### HIGH PRIORITY Fixes Applied ✅

1. **Comprehensive Assertions** - Every test now verifies:
   - Exact handler invocation count (not "at least")
   - Message data integrity (payload reached handler correctly)
   - Explicit failed transport state (`queue_name='failed'`, not just row count)
   - Cleanup state verification (tables empty after rollback)

2. **AMQP Exception Handling (CRITICAL FIX)**:
   - Inbox tests now FAIL if AMQP is unavailable (was silently swallowed)
   - Prevents false positives when RabbitMQ is down
   - Outbox tests can continue without AMQP (only test database storage)

3. **Defensive tearDown**:
   - Always resets handlers even if test fails
   - Prevents static state leakage between tests
   - Critical for test isolation

4. **Critical Missing Assertion (Suite 3, Test 2)**:
   - Added: `assertEquals(1, getDeduplicationEntryCount())`
   - Verifies EXACTLY one dedup entry (not zero, not two)
   - Detects duplicate processing bugs

### MEDIUM PRIORITY Improvements Recommended 📝

These can be added during implementation or code review:
- Data providers for retry scenarios (vary retry counts)
- Test annotations (`@group`, `@covers`, `@testdox`)
- Transaction boundary verification (meta-test)
- Edge case expansion (empty body, type mismatches, null values)

### Test Quality Grade: B+ → A-

With Kieran's fixes applied, this plan moves from "very good" to "excellent" quality:
- Stronger assertions catch more bugs
- AMQP fix prevents false positives
- Defensive tearDown ensures test isolation
- Comprehensive coverage without hypothetical scenarios

**Ready for implementation with confidence.**

---

**Document Status**: 📋 READY FOR IMPLEMENTATION (Kieran-reviewed, HIGH PRIORITY fixes applied)
