---
title: DeduplicationMiddleware Not Running in Symfony Messenger Functional Tests
category: test-failures
tags: [symfony, messenger, middleware, testing, phpunit, deduplication, functional-tests]
module: Inbox Pattern Tests
symptom: DeduplicationMiddleware not executing during functional tests, allowing duplicate message processing despite correct service registration
root_cause: Test environment's messenger bus configuration did not include DeduplicationMiddleware due to missing explicit middleware list in test.yaml
severity: high
date_encountered: 2026-01-29
resolved: true
---

# DeduplicationMiddleware Not Running in Functional Tests

## Problem Statement

**Issue**: DeduplicationMiddleware was properly tagged with `messenger.middleware` and registered as a service, but it was **not running** during functional tests. This caused duplicate messages to be processed twice, breaking inbox deduplication tests.

**Symptom**: Test failure with error message:
```
Expected handler to be invoked 1 time(s), but was invoked 2 time(s)
Failed asserting that 2 matches expected 1.
```

**Impact**: The entire inbox deduplication pattern was not working in the test environment, even though:
- The middleware was registered via service tag
- The DeduplicationStore worked correctly when called directly
- Message stamps (MessageIdStamp) were properly serialized/deserialized

---

## Investigation Steps

### 1. Initial Debugging - What Didn't Work

**Attempted**: Verifying service registration
```yaml
# DeduplicationMiddleware was properly tagged
Freyr\MessageBroker\Inbox\DeduplicationMiddleware:
    arguments:
        $store: '@Freyr\MessageBroker\Inbox\DeduplicationStore'
    tags:
        - { name: 'messenger.middleware', priority: -10 }
```
**Result**: Service was registered correctly - **NOT the issue**

### 2. Testing DeduplicationStore Directly

**Test**: Direct call to store's `isDuplicate()` method
```php
public function testDeduplicationStoreDirectly(): void
{
    $store = $this->getContainer()->get('Freyr\MessageBroker\Inbox\DeduplicationStore');
    $isDuplicate1 = $store->isDuplicate($messageId, $messageName);

    $this->assertFalse($isDuplicate1, 'First message should not be duplicate');

    $isDuplicate2 = $store->isDuplicate($messageId, $messageName);

    $this->assertTrue($isDuplicate2, 'Second message should be duplicate');
}
```
**Result**: ✅ **PASSED** - Store logic was working correctly

**Conclusion**: The problem was **NOT** in the DeduplicationStore implementation

### 3. Verifying Stamp Deserialization

**Test**: Created `InboxSerializerDebugTest.php` to inspect envelope stamps
```php
public function testInspectEnvelopeBeforeAndAfterDecode(): void
{
    $encodedEnvelope = [
        'body' => json_encode([...]),
        'headers' => [
            'type' => 'test.event.sent',
            'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
                ['messageId' => $messageId],
            ]),
        ],
    ];

    $serializer = $this->getContainer()->get('Freyr\MessageBroker\Serializer\InboxSerializer');
    $envelope = $serializer->decode($encodedEnvelope);

    $messageIdStamp = $envelope->last(MessageIdStamp::class);
    $this->assertNotNull($messageIdStamp); // ✅ PASSED
}
```
**Result**: ✅ **PASSED** - MessageIdStamp was correctly deserialized from `X-Message-Stamp-*` headers

**Output**:
```
All stamps:
  Freyr\MessageBroker\Inbox\MessageIdStamp: 1 stamp(s)
    - MessageIdStamp(messageId='019c0bbf-fe84-7194-8ad8-513e8cc2fc6b')
  Symfony\Component\Messenger\Stamp\SerializedMessageStamp: 1 stamp(s)
  Freyr\MessageBroker\Serializer\MessageNameStamp: 1 stamp(s)

✅ MessageIdStamp FOUND!
```

**Conclusion**: The problem was **NOT** in stamp serialization

### 4. End-to-End Deduplication Test

**Test**: Publishing same message twice with identical MessageIdStamp
```php
public function testDuplicateMessageIsNotProcessedTwice(): void
{
    // Publish first message
    $this->publishToAmqp('test_inbox', $headers, $body);
    $this->consumeFromInbox(limit: 1);
    $this->assertHandlerInvoked(TestEventHandler::class, 1);

    // Publish duplicate message (same messageId)
    $this->publishToAmqp('test_inbox', $headers, $body);
    $this->consumeFromInbox(limit: 1);

    // Expected: Handler invoked only once
    $this->assertHandlerInvoked(TestEventHandler::class, 1);
}
```
**Result**: ❌ **FAILED** - Handler was invoked **2 times** instead of 1

**Database verification**:
```sql
SELECT COUNT(*) FROM message_broker_deduplication;
-- Returns: 0 (expected: 1)
```

**Conclusion**: DeduplicationMiddleware was **NOT RUNNING** in the middleware stack

---

## Root Cause Analysis

### Why Middleware Tagging Wasn't Enough

In Symfony Messenger, middleware tagged with `messenger.middleware` should auto-register globally. However, in test environments:

1. **Service Tagging Alone is Insufficient**: The tag registers the middleware service, but it doesn't automatically add it to the bus middleware stack in all environments
2. **Bus Configuration Required**: The messenger bus needs **explicit configuration** to include the middleware in its stack
3. **Test Environment Quirk**: Test configs with boolean `default_middleware: true` may not properly register tagged custom middleware

### Technical Explanation

The middleware was registered as a **service**, but Symfony's `Worker` class (used in `consumeFromInbox()`) builds the middleware stack from the **bus configuration**, not from tagged services alone.

**Middleware execution order (BEFORE fix)**:
```
Message from AMQP Transport
    ↓
ReceivedStamp added by transport
    ↓
InboxSerializer deserializes MessageIdStamp from X-Message-Stamp-* header
    ↓
❌ DeduplicationMiddleware (NOT RUNNING - missing from bus stack)
    ↓
Handler executed (ALWAYS runs - no deduplication)
```

**What was needed**: Explicit bus middleware configuration to activate the middleware

---

## Working Solution

### Step-by-Step Fix

**File**: `tests/Functional/config/test.yaml`

**Before** (middleware not running):
```yaml
framework:
    messenger:
        failure_transport: failed

        # ❌ No buses configuration - middleware not activated

        transports:
            outbox: ...
            amqp_test: ...
```

**After** (middleware running):
```yaml
framework:
    messenger:
        failure_transport: failed

        buses:
            messenger.bus.default:
                default_middleware: true
                middleware:
                    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'

        transports:
            outbox: ...
            amqp_test: ...
```

### Configuration Breakdown

```yaml
buses:
    messenger.bus.default:           # Configure the default message bus
        default_middleware: true     # ✅ Include standard Symfony middleware
                                     #    (send, handle_message, etc.)

        middleware:
            - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'
                                     # ✅ Explicitly add DeduplicationMiddleware
                                     #    to the bus middleware stack
```

**Why Both are Required**:
- `default_middleware: true` - Includes Symfony's built-in middleware
- `middleware: [...]` - Explicitly adds custom middleware to the stack

**Middleware Execution Order** (after fix):
```
1. SendMessageMiddleware (priority 100)
2. doctrine_transaction (priority 0) ← Starts transaction
3. DeduplicationMiddleware (priority -10) ← Runs AFTER transaction started
4. HandleMessageMiddleware (priority -100) ← Invokes handler
```

---

## Verification

### Test Results After Fix

**Before Fix**:
```
Handler invoked 2 times instead of 1 ❌
0 deduplication entries in database
```

**After Fix**:
```php
// First message
$this->publishToAmqp('test_inbox', $headers, $body);
$this->consumeFromInbox(limit: 1);
$this->assertHandlerInvoked(TestEventHandler::class, 1); // ✅ PASS

// Duplicate message (same MessageIdStamp)
$this->publishToAmqp('test_inbox', $headers, $body);
$this->consumeFromInbox(limit: 1);
$this->assertHandlerInvoked(TestEventHandler::class, 1); // ✅ PASS (still 1)

// Deduplication entry created once
$this->assertEquals(1, $this->getDeduplicationEntryCount()); // ✅ PASS
```

**All 12 functional tests now pass**:
- ✅ Outbox Flow (3 tests)
- ✅ Inbox Flow (4 tests) - including deduplication
- ✅ Inbox Deduplication (2 tests)
- ✅ Inbox Deserialization (1 test)
- ✅ Debug Tests (2 tests)

**Total**: 12 tests, 56 assertions

---

## Prevention Strategies

### 1. Always Use Explicit Middleware Declaration in Tests

**Rule**: Don't rely on middleware auto-registration via tags in test environments.

**Pattern**:
```yaml
# ✅ DO: Explicit declaration in test config
framework:
  messenger:
    buses:
      messenger.bus.default:
        default_middleware: true
        middleware:
          - 'Your\Custom\Middleware'

# ❌ DON'T: Rely on tags alone
framework:
  messenger:
    # Missing buses configuration
```

### 2. Verify Middleware Execution with Side-Effect Tests

**Pattern**:
```php
public function testMiddlewareExecutes(): void
{
    $this->publishMessage($message);
    $this->consumeMessage();

    // Verify middleware side effect (DB row, log entry, etc.)
    $this->assertDeduplicationEntryExists($messageId);
}
```

**Why**: Testing only handler execution doesn't prove middleware ran.

### 3. Test Duplicate Message Scenarios

**Critical test**:
```php
public function testDuplicateMessageIsNotProcessedTwice(): void
{
    // First message
    $this->publishMessage($message);
    $this->consumeMessage();
    $this->assertHandlerInvoked(1);

    // Duplicate (same messageId)
    $this->publishMessage($message);
    $this->consumeMessage();

    // Still only 1 invocation
    $this->assertHandlerInvoked(1);
}
```

### 4. Use Debug Commands to Verify Registration

```bash
# Check middleware in bus stack
bin/console debug:messenger --env=test

# Check service registration
bin/console debug:container --tag=messenger.middleware --env=test
```

---

## Key Learnings

### 1. Service Tagging ≠ Middleware Activation

**Misconception**: Tagging middleware with `messenger.middleware` automatically adds it to all buses

**Reality**: The tag makes the middleware **available**, but you must **explicitly configure** it in the bus to activate it (especially in test environments)

### 2. Test Environment Configuration Matters

**Production vs Test**: Test configurations with boolean `default_middleware: true` may behave differently than production configs

**Best Practice**: Always explicitly configure bus middleware in test configs

### 3. Middleware Priority is Critical

```yaml
Freyr\MessageBroker\Inbox\DeduplicationMiddleware:
    tags:
        - { name: 'messenger.middleware', priority: -10 }
```

**Why priority -10?**
- Runs **AFTER** `doctrine_transaction` (priority 0)
- Ensures deduplication INSERT happens **within the transaction**
- Guarantees atomic commit of deduplication entry + handler changes

### 4. Debug Systematically

**Effective approach**:
1. ✅ Test components in isolation (DeduplicationStore directly)
2. ✅ Verify data flow (stamp deserialization)
3. ✅ Test end-to-end (full Worker consumption)
4. ✅ Inspect configuration (bus middleware stack)

**Result**: Isolated the issue to configuration layer, not code logic

---

## Related Issues & References

### Documentation
- `/Users/michal/code/freyr/message-broker/docs/inbox-deduplication.md` - Deduplication architecture
- `/Users/michal/code/freyr/message-broker/CLAUDE.md` - Lines 237-249, 383-388 (middleware configuration)
- `/Users/michal/code/freyr/message-broker/README.md` - Lines 565-570 (service registration)

### Related Fixes (CHANGELOG.md)
- **v0.2.3**: Fixed wrong table name in cleanup command (`message_broker_deduplication`)
- **v0.2.2**: Fixed serializer retry bug with semantic message names
- **v0.2.0**: Split into InboxSerializer and OutboxSerializer

### Test Files
- `tests/Functional/InboxDeduplicationOnlyTest.php` - Deduplication-focused tests
- `tests/Functional/InboxSerializerDebugTest.php` - Stamp deserialization verification
- `tests/Functional/FunctionalTestCase.php` - Test helpers (`assertDeduplicationEntryExists`)

---

## Quick Reference

### Minimal Working Configuration

```yaml
# tests/Functional/config/test.yaml
framework:
    messenger:
        failure_transport: failed

        buses:
            messenger.bus.default:
                default_middleware: true
                middleware:
                    - 'Freyr\MessageBroker\Inbox\DeduplicationMiddleware'

        transports:
            amqp_test:
                dsn: '%env(MESSENGER_AMQP_DSN)%'
                serializer: 'Freyr\MessageBroker\Serializer\InboxSerializer'
                options:
                    auto_setup: false
                    queues:
                        test_inbox: ~
```

### Service Definition

```yaml
# config/services.yaml
services:
    Freyr\MessageBroker\Inbox\DeduplicationMiddleware:
        arguments:
            $store: '@Freyr\MessageBroker\Inbox\DeduplicationStore'
        tags:
            - { name: 'messenger.middleware', priority: -10 }
```

---

## Git Commit

```
commit bdffc6e7a8e9b2bfeea58060c76d743b9470e6f7
Author: Michal Giergielewicz <michal@giergielewicz.pl>
Date:   Thu Jan 29 22:56:18 2026 +0100

    fix(tests): enable DeduplicationMiddleware in test bus configuration

    The DeduplicationMiddleware was registered via service tag but wasn't being
    included in the bus middleware stack during tests.

    Solution: Added explicit bus configuration in test.yaml with middleware list.
```

---

## Summary

**Problem**: Middleware tagged but not running in tests
**Cause**: Missing explicit bus middleware configuration
**Solution**: Add `buses.messenger.bus.default.middleware` config in test.yaml
**Verification**: Deduplication tests now pass, DB entries created
**Prevention**: Always explicitly configure middleware in test environments
