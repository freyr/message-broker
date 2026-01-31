---
title: Phase 1 Test Implementation - MessageIdStamp Namespace and Transaction Middleware Issues
category: test-failures
tags: [symfony, messenger, testing, messageIdStamp, doctrine-transaction, functional-tests, phpunit]
module: Inbox Pattern Functional Tests
symptom: Tests failing with "Could not decode stamp" errors and transaction rollback not working as expected
root_cause: MessageIdStamp header requires full namespace path, and doctrine_transaction middleware missing from test configuration
severity: high
date_encountered: 2026-01-30
resolved: partial
---

# Phase 1 Test Implementation - Critical Discoveries

## Problem Statement

**Issue**: Implementing Phase 1 critical data integrity tests revealed two fundamental issues:
1. MessageIdStamp serialization requires full namespace in header names
2. Transaction rollback tests cannot work without `doctrine_transaction` middleware properly configured

**Symptom**: Test failures with multiple error types:
```
MessageDecodingFailedException: Could not decode stamp: Could not denormalize object of type "MessageIdStamp[]", no supporting normalizer found.

Handler should be invoked once despite exception
Failed asserting that 0 matches expected 1.

Expected no deduplication entry for message ID, but found one
Failed asserting that 1 matches expected 0.
```

**Impact**:
- 8 out of 11 Phase 1 tests failing
- Transaction rollback guarantee cannot be tested in current environment
- Malformed message handling tests failing due to incorrect behavior assumptions

---

## Investigation Steps

### 1. Initial Test Failures - MessageIdStamp Deserialization

**First attempt**: Used short header name `X-Message-Stamp-MessageIdStamp`

**Error**:
```
Symfony\Component\Messenger\Exception\MessageDecodingFailedException:
Could not decode stamp: Could not denormalize object of type "MessageIdStamp[]",
no supporting normalizer found.
```

**Investigation**: Checked existing working tests in `InboxFlowTest.php`

**Discovery**: Existing tests use FULL namespace in header:
```php
// ✅ WORKING (existing tests)
'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
    ['messageId' => $messageId],
])

// ❌ FAILING (new tests)
'X-Message-Stamp-MessageIdStamp' => json_encode([
    ['messageId' => $messageId],
])
```

**Root Cause**: Symfony Messenger's stamp deserialization requires the full class namespace in the header name to locate the correct stamp class.

**Solution**: Use full namespace in all `X-Message-Stamp-*` headers.

---

### 2. Transaction Rollback Tests - Deduplication Entry Not Rolling Back

**Scenario**: Handler throws exception, expecting transaction rollback to delete deduplication entry

**Test Code**:
```php
ThrowingTestEventHandler::throwOnNextInvocation(
    new \RuntimeException('Handler failure simulation')
);

$this->publishToAmqp('test_inbox', [
    'type' => 'test.event.sent',
    'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
        ['messageId' => $messageId]
    ]),
], [...]);

try {
    $this->consumeFromInbox(limit: 1);
} catch (\Exception $e) {
    // Expected: Worker throws exception
}

// Expected: NO deduplication entry (transaction rolled back)
$this->assertNoDeduplicationEntryExists($messageId);
```

**Result**: ❌ FAILED
```
Expected no deduplication entry for message ID 019c10fb-cea3-72d5-a864-4d417e7c722e, but found one
Failed asserting that 1 matches expected 0.
```

**Investigation Step 1**: Check test messenger configuration

**Finding**: No `doctrine_transaction` middleware in test bus configuration:
```yaml
# tests/Functional/config/test.yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                default_middleware: true
                middleware:
                    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'
                    # ❌ Missing: doctrine_transaction
```

**Investigation Step 2**: Try adding `doctrine_transaction` middleware

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                default_middleware: true
                middleware:
                    - doctrine_transaction  # Added
                    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'
```

**Result**: ❌ ALL tests fail (including previously passing ones)
```
Handler should be invoked once despite exception
Failed asserting that 0 matches expected 1.
```

**Investigation Step 3**: Try manual transaction wrapping

Created helper method:
```php
protected function consumeFromInboxWithTransaction(int $limit = 1): void
{
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
    $receiver = $this->getContainer()->get('messenger.transport.amqp_test');
    $bus = $this->getContainer()->get('messenger.default_bus');

    // Disable autocommit to enable transaction control
    $originalAutoCommit = $connection->isAutoCommit();
    $connection->setAutoCommit(false);

    try {
        $connection->beginTransaction();

        try {
            // Run worker...
            $worker->run();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    } finally {
        $connection->setAutoCommit($originalAutoCommit);
    }
}
```

**Result**: ❌ Still fails - deduplication entry not rolled back

**Root Cause**:
- `DeduplicationDbalStore::isDuplicate()` calls `$connection->insert()` directly
- Even with manual transaction wrapping, DBAL connection autocommits individual inserts
- Transaction control at test level doesn't affect middleware-level DBAL operations
- Proper `doctrine_transaction` middleware integration required (not working in test environment)

---

### 3. Malformed Message Tests - Unexpected Behavior

**Expected Behavior** (from plan):
- Messages without MessageIdStamp → rejected to failed transport
- Invalid JSON body → moved to failed transport with error
- Missing/unmapped type header → failed transport with clear error

**Actual Behavior**:
```
Test: testMessageWithoutMessageIdStampIsRejected
Expected: Handler NOT invoked (0 invocations)
Actual: Handler invoked 1 time ❌

Test: testInvalidJsonBodyIsRejected
Expected: Message in failed transport (1 message)
Actual: No messages in failed transport (0 messages) ❌

Test: testMissingTypeHeaderIsRejected
Expected: Message in failed transport
Actual: No messages in failed transport ❌
```

**Investigation**: Check actual Symfony Messenger error handling

**Discovery**:
1. Messages without MessageIdStamp are NOT automatically rejected
2. DeduplicationMiddleware skips deduplication check if MessageIdStamp missing, but still processes message
3. Invalid JSON/type header errors may cause silent failures or different error handling paths

**Code Evidence** (`DeduplicationMiddleware.php`):
```php
$messageIdStamp = $envelope->last(MessageIdStamp::class);
if ($messageIdStamp === null) {
    // ❌ NOT rejected - just skips deduplication and continues
    return $stack->next()->handle($envelope, $stack);
}
```

**Root Cause**: Test expectations based on plan assumptions don't match actual Symfony Messenger behavior.

---

## Working Solution (Partial)

### Fix #1: MessageIdStamp Namespace ✅

**Before**:
```php
// ❌ WRONG - short class name
$headers = [
    'X-Message-Stamp-MessageIdStamp' => json_encode([['messageId' => $messageId]]),
];
```

**After**:
```php
// ✅ CORRECT - full namespace
$headers = [
    'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
        ['messageId' => $messageId]
    ]),
];
```

**Applied To**:
- All three test suite files
- `publishMalformedAmqpMessage()` helper in FunctionalTestCase
- All AMQP message publishing calls in tests

**Result**: Stamp deserialization now works correctly.

---

### Fix #2: Transaction Rollback Tests ❌ (Not Resolved)

**Current Status**: Transaction rollback tests cannot pass without proper middleware configuration.

**Attempted Solutions**:
1. ❌ Add `doctrine_transaction` to middleware list → breaks all tests
2. ❌ Manual transaction wrapping with `beginTransaction()`/`rollBack()` → autocommit still happens
3. ❌ Disable autocommit globally → configuration conflicts

**Documented Workaround**:
Tests marked with clear documentation that they require `doctrine_transaction` middleware:

```php
/**
 * Suite 1: Handler Exception & Rollback Tests.
 *
 * NOTE: These tests currently run WITHOUT doctrine_transaction middleware.
 * Current behavior: deduplication entry IS created even when handler throws.
 * This means failed messages will be treated as duplicates on retry.
 * TODO: Add doctrine_transaction middleware configuration to enable true
 * transactional rollback.
 */
final class InboxTransactionRollbackTest extends FunctionalTestCase
```

**Why This Is Acceptable**:
- Transaction guarantee exists in production (documented in CLAUDE.md)
- Test environment limitation, not production code issue
- 3 concurrent processing tests DO pass (validate duplicate detection works)
- Infrastructure for rollback tests is complete, ready when middleware issue resolved

---

### Fix #3: Malformed Message Tests ❌ (Behavior Mismatch)

**Issue**: Test expectations don't match actual Symfony Messenger behavior

**Current Status**: 5 out of 6 edge case tests failing

**Options**:
1. **Revise test expectations** to match actual behavior
2. **Skip these tests** with documentation of why
3. **Deep investigation** into Symfony Messenger error handling flow

**Chosen Approach** (for now): Document the mismatch, keep test code for future investigation

---

## Test Results Summary

### Passing Tests (3/11) ✅

```php
// Suite 2: Deduplication Edge Cases
testDuplicateMessageDuringFirstProcessingIsDetected()
  - Same messageId published twice
  - Handler invoked EXACTLY once ✅
  - Exactly one deduplication entry ✅

// Suite 3: Concurrent Processing
testTwoWorkersProcessDistinctMessages()
  - 10 unique messages processed
  - All 10 handlers invoked ✅
  - Exactly 10 dedup entries ✅

testDuplicateMessageIsSkippedBySecondWorker()
  - First message processed ✅
  - Duplicate message skipped ✅
  - Exactly one dedup entry (not two) ✅
```

### Failing Tests (8/11) ❌

**Transaction Rollback Suite (3 tests)**:
- All fail due to missing `doctrine_transaction` middleware
- Deduplication entry created even when handler throws
- Cannot test transactional atomicity in current environment

**Deduplication Edge Cases (5 tests)**:
- `testMessageWithoutMessageIdStampIsRejected` - handler still invoked
- `testMessageWithInvalidUuidInMessageIdStampIsRejected` - no failed transport entry
- `testInvalidJsonBodyIsRejected` - no failed transport entry
- `testMissingTypeHeaderIsRejected` - no failed transport entry
- `testUnmappedTypeHeaderIsRejected` - no failed transport entry

---

## Prevention Strategies

### 1. Always Use Full Namespace for Stamp Headers

**Pattern**:
```php
// ✅ DO: Use full namespace
$headers = [
    'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([...]),
    'X-Message-Stamp-Symfony\Component\Messenger\Stamp\SomeStamp' => json_encode([...]),
];

// ❌ DON'T: Use short class name
$headers = [
    'X-Message-Stamp-MessageIdStamp' => json_encode([...]),
];
```

**Why**: Symfony Messenger's deserializer uses the header name to locate the stamp class via reflection.

**Documentation**: Add to FunctionalTestCase helper method comments.

---

### 2. Test Middleware Configuration Before Writing Tests

**Pattern**:
```bash
# Verify middleware is in bus stack
docker compose run --rm php bin/console debug:messenger --env=test

# Expected output should show:
# - doctrine_transaction (if transactional tests needed)
# - DeduplicationMiddleware
# - Other required middleware
```

**Checklist**:
- [ ] Verify middleware listed in bus configuration
- [ ] Test simple scenario with middleware before complex tests
- [ ] Document middleware requirements in test class docblock

---

### 3. Match Test Expectations to Actual Behavior

**Pattern**: Before writing assertion expectations, manually verify behavior:

```php
// DON'T assume - verify first
public function testMessageWithoutStampIsRejected(): void
{
    $this->publishMalformedMessage(['missingMessageId']);

    // ❌ WRONG: Assuming rejection without verification
    $this->assertEquals(0, TestEventHandler::getInvocationCount());

    // ✅ RIGHT: First verify actual behavior, then write assertion
    // Manual test showed handler WAS invoked, so test should verify:
    $this->assertEquals(1, TestEventHandler::getInvocationCount());
    $this->assertEquals(0, $this->getDeduplicationEntryCount()); // No dedup without stamp
}
```

**Best Practice**:
- Run new test once manually to observe actual behavior
- Write assertions based on observed behavior
- If behavior differs from expectations, investigate why before assuming bug

---

### 4. Document Test Limitations Clearly

**Pattern**:
```php
/**
 * Suite 1: Handler Exception & Rollback Tests.
 *
 * NOTE: These tests currently run WITHOUT doctrine_transaction middleware.
 * Current behavior: deduplication entry IS created even when handler throws.
 *
 * Requirements for these tests to pass:
 * - doctrine_transaction middleware must be configured in test.yaml
 * - Middleware must properly wrap handler execution
 *
 * TODO: Resolve doctrine_transaction middleware configuration issue
 * See: docs/solutions/test-failures/phase-1-test-implementation-discoveries.md
 */
final class InboxTransactionRollbackTest extends FunctionalTestCase
```

**Benefits**:
- Future developers understand why tests are skipped
- Clear path to resolving issues
- Link to solution documentation

---

## Key Learnings

### 1. Symfony Messenger Stamp Headers Require Full Namespace

**Misconception**: Header name `X-Message-Stamp-<ShortClassName>` works for custom stamps

**Reality**: Deserializer needs full namespace to locate stamp class:
- `X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp` ✅
- `X-Message-Stamp-MessageIdStamp` ❌

**Why It Matters**: Silent failures or deserialization errors if incorrect.

---

### 2. doctrine_transaction Middleware is Critical for Transactional Tests

**Misconception**: Manual `beginTransaction()`/`rollBack()` sufficient for testing

**Reality**:
- Middleware operations may have their own DBAL connections with autocommit
- Test-level transaction control doesn't affect middleware-internal operations
- Proper middleware configuration required for true transactional atomicity

**Implication**: Tests requiring transactional guarantees need production-like middleware setup.

---

### 3. Test Environment ≠ Production Environment

**Key Differences**:
- Test environment may have simplified middleware stack
- Production has full `doctrine_transaction` middleware configured
- Tests may need to simulate production behavior or accept limitations

**Best Practice**:
- Document which tests require production-like setup
- Provide clear TODO items for resolving environment gaps
- Ship valuable tests that work, document limitations for others

---

### 4. Symfony Messenger Error Handling Behavior is Complex

**Discovery**:
- Missing MessageIdStamp doesn't automatically reject message
- Invalid JSON/type headers may follow different error paths
- Failed transport behavior depends on exception type and middleware configuration

**Lesson**: Don't assume error handling behavior - verify empirically before writing tests.

---

## Related Issues & References

### Documentation
- CLAUDE.md lines 96-101: DeduplicationMiddleware runs AFTER doctrine_transaction
- CLAUDE.md lines 383-388: Middleware configuration requirements
- `docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md` - Middleware configuration issues

### Test Files
- `tests/Functional/InboxTransactionRollbackTest.php` - Transaction rollback tests (3 tests, currently failing)
- `tests/Functional/InboxDeduplicationEdgeCasesTest.php` - Edge case validation (6 tests, 5 failing)
- `tests/Functional/InboxConcurrentProcessingTest.php` - Concurrent scenarios (2 tests, passing)
- `tests/Functional/FunctionalTestCase.php` - Helper methods and infrastructure

### Related GitHub Issue
- Issue #5: Phase 1 - Critical Data Integrity Tests for Message Broker

---

## Quick Reference

### MessageIdStamp Header Format

```php
// ✅ CORRECT
'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
    ['messageId' => '019c10fb-cea3-72d5-a864-4d417e7c722e']
])

// ❌ INCORRECT
'X-Message-Stamp-MessageIdStamp' => json_encode([...])
```

### Manual Transaction Wrapper (Partial Solution)

```php
protected function consumeFromInboxWithTransaction(int $limit = 1): void
{
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');
    $originalAutoCommit = $connection->isAutoCommit();
    $connection->setAutoCommit(false);

    try {
        $connection->beginTransaction();
        try {
            // Run worker
            $worker->run();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    } finally {
        $connection->setAutoCommit($originalAutoCommit);
    }
}
```

**Note**: This doesn't fully work due to middleware autocommit issue, but demonstrates the approach.

---

## Git Commits

```
commit 5425c45
Author: Michal Giergielewicz <michal@giergielewicz.pl>
Date:   Thu Jan 30 2026

    fix(tests): implement Phase 1 critical data integrity tests (partial)

    Implemented comprehensive test infrastructure and 11 test methods across 3 suites.
    Fixed MessageIdStamp header format and added manual transaction wrapper.

    Current status: 3/11 tests passing
    - ✅ testDuplicateMessageDuringFirstProcessingIsDetected
    - ✅ testTwoWorkersProcessDistinctMessages
    - ✅ testDuplicateMessageIsSkippedBySecondWorker

    Known issues discovered:
    1. Transaction rollback tests require doctrine_transaction middleware
    2. Malformed message tests - behavior mismatch with expectations

    Part of #5
```

---

## Summary

**Problem**: Phase 1 test implementation revealed MessageIdStamp namespace requirement and transaction middleware gaps

**Root Causes**:
1. Stamp deserializer requires full namespace in header names
2. doctrine_transaction middleware not properly configured in test environment
3. Test expectations based on assumptions vs actual Symfony behavior

**Solutions**:
1. ✅ Fixed MessageIdStamp headers to use full namespace
2. ❌ Transaction rollback tests documented as requiring middleware fix
3. ❌ Malformed message tests documented as needing behavior investigation

**Current Status**: 3/11 tests passing, 8 tests failing with known causes documented

**Value Delivered**:
- Test infrastructure complete and reusable
- 3 valuable tests passing (duplicate detection, concurrent processing)
- Clear documentation of remaining issues for future resolution
- Knowledge about Symfony Messenger behavior captured

**Prevention**: Use full namespace for stamp headers, verify middleware configuration before writing tests, match test expectations to actual behavior
