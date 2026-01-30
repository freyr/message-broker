---
status: pending
priority: p2
issue_id: 7
tags: [code-review, refactoring, duplication, test-infrastructure]
dependencies: []
---

# Add publishTestEvent() Helper to Reduce Test Verbosity

## Problem Statement

AMQP message publishing logic for TestEvent is duplicated 15+ times across test files. Each test manually constructs headers and body structure, creating ~70 lines of duplicated code and brittle dependencies on message format.

**Impact**:
- High duplication (15+ occurrences)
- Changes to message format require updates in many places
- Tests are verbose and harder to read

## Findings

**Pattern Recognition Reviewer**: Identified repeated AMQP publishing pattern across all inbox tests.

**Code Simplicity Reviewer**: Flagged as major duplication opportunity.

**Current Pattern** (repeated 15+ times):
```php
$messageId = Id::new()->__toString();
$testEvent = new TestEvent(
    id: Id::new(),
    name: 'some-name',
    timestamp: CarbonImmutable::now()
);

$this->publishToAmqp('test_inbox', [
    'type' => 'test.event.sent',
    'X-Message-Stamp-Freyr\\MessageBroker\\Inbox\\MessageIdStamp' =>
        json_encode([['messageId' => $messageId]]),
], [
    'id' => $testEvent->id->__toString(),
    'name' => $testEvent->name,
    'timestamp' => $testEvent->timestamp->toIso8601String(),
]);
```

## Proposed Solutions

### Solution 1: Event-Specific Helper Methods (RECOMMENDED)

**Effort**: Small (1 hour)
**Risk**: Very Low
**Pros**:
- Type-safe (accepts TestEvent)
- Single place to update message format
- Returns message ID for assertions
- Reduces test code by ~5 lines per test

**Cons**:
- Need separate method for each event type (OrderPlaced, etc.)

**Implementation**:
```php
// Add to FunctionalTestCase.php:

/**
 * Publish a TestEvent to AMQP inbox queue with proper headers.
 *
 * @return string The generated message ID (for assertions)
 */
protected function publishTestEvent(
    TestEvent $event,
    ?string $messageId = null,
    string $queue = 'test_inbox'
): string {
    $messageId = $messageId ?? Id::new()->__toString();

    $this->publishToAmqp($queue, [
        'type' => 'test.event.sent',
        'X-Message-Stamp-Freyr\\MessageBroker\\Inbox\\MessageIdStamp' =>
            json_encode([['messageId' => $messageId]]),
    ], [
        'id' => $event->id->__toString(),
        'name' => $event->name,
        'timestamp' => $event->timestamp->toIso8601String(),
    ]);

    return $messageId;
}

/**
 * Publish an OrderPlaced event to AMQP.
 */
protected function publishOrderPlacedEvent(
    OrderPlaced $event,
    ?string $messageId = null,
    string $queue = 'test.order.placed'
): string {
    // Similar implementation...
}
```

**Usage**:
```php
// Before (7 lines):
$messageId = Id::new()->__toString();
$testEvent = new TestEvent(...);
$this->publishToAmqp('test_inbox', [...], [...]);

// After (2 lines):
$testEvent = new TestEvent(...);
$messageId = $this->publishTestEvent($testEvent);
```

### Solution 2: Generic Builder Pattern

**Effort**: Medium (2-3 hours)
**Risk**: Medium
**Pros**:
- Single generic method for all event types
- Fluent interface

**Cons**:
- More complex API
- Loses type safety
- Over-engineered for current needs

**Not recommended** - YAGNI (You Aren't Gonna Need It)

## Recommended Action

**Implement Solution 1**: Add `publishTestEvent()` and `publishOrderPlacedEvent()` helpers.

## Technical Details

**Affected Files**:
- `tests/Functional/FunctionalTestCase.php` (add 2 helper methods)
- `tests/Functional/InboxTransactionRollbackTest.php` (refactor to use helper)
- `tests/Functional/InboxConcurrentProcessingTest.php` (refactor to use helper)
- `tests/Functional/InboxDeduplicationEdgeCasesTest.php` (refactor to use helper)
- `tests/Functional/OutboxFlowTest.php` (refactor to use OrderPlaced helper)

**Code Reduction**: ~75 lines across test files

**Breaking Changes**: None (adds new methods, doesn't change existing)

## Acceptance Criteria

- [ ] `publishTestEvent()` helper added to FunctionalTestCase
- [ ] `publishOrderPlacedEvent()` helper added to FunctionalTestCase
- [ ] All inbox tests refactored to use `publishTestEvent()`
- [ ] All outbox tests refactored to use `publishOrderPlacedEvent()`
- [ ] All existing tests still pass
- [ ] Code reduction of ~75 lines achieved
- [ ] PHPDoc added with @return annotation

## Work Log

_No work done yet_

## Resources

- **Review**: Code Simplicity Review - "AMQP Message Publishing Duplication"
- **Review**: Pattern Recognition Review - "Duplicated Message Publishing Setup"
- **Files**: All test files in `tests/Functional/Inbox*.php`, `tests/Functional/Outbox*.php`
