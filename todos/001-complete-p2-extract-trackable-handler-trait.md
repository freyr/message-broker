---
status: complete
priority: p2
issue_id: 7
tags: [code-review, refactoring, code-simplicity, duplication]
dependencies: []
---

# Extract TrackableHandlerTrait to Eliminate Handler Duplication

## Problem Statement

Three test fixture handlers contain 92% identical code for tracking invocations and messages. This violates DRY principle and creates maintenance burden.

**Impact**: ~80 lines of duplicated code that must be updated in 3 places for any tracking logic changes.

## Findings

**Code Simplicity Reviewer**: Identified massive duplication across handlers:
- `TestEventHandler.php` (40 lines)
- `OrderPlacedHandler.php` (40 lines)
- `ThrowingTestEventHandler.php` (59 lines - includes unique exception logic)

All three implement identical patterns:
```php
private static int $invocationCount = 0;
private static ?MessageType $lastMessage = null;

public static function getInvocationCount(): int
public static function getLastMessage(): ?MessageType
public static function reset(): void
```

## Proposed Solutions

### Solution 1: Trait-Based Approach (RECOMMENDED)

**Effort**: Small (1-2 hours)
**Risk**: Low
**Pros**:
- Reuses PHP trait mechanism
- Single source of truth for tracking
- Type-safe with mixed type
- Easy to enhance (add timestamps, etc.)

**Cons**:
- Requires PHP 8.4 for mixed type
- Static state still present (acceptable for tests)

**Implementation**:
```php
// New: tests/Functional/Fixtures/TrackableHandlerTrait.php
trait TrackableHandlerTrait
{
    private static int $invocationCount = 0;
    private static mixed $lastMessage = null;

    protected function track(mixed $message): void
    {
        self::$invocationCount++;
        self::$lastMessage = $message;
    }

    public static function getInvocationCount(): int
    {
        return self::$invocationCount;
    }

    public static function getLastMessage(): mixed
    {
        return self::$lastMessage;
    }

    public static function reset(): void
    {
        self::$invocationCount = 0;
        self::$lastMessage = null;
    }
}

// Updated: TestEventHandler.php
final class TestEventHandler
{
    use TrackableHandlerTrait;

    public function __invoke(TestEvent $message): void
    {
        $this->track($message);
    }
}
```

### Solution 2: Abstract Base Class

**Effort**: Medium (2-3 hours)
**Risk**: Low
**Pros**:
- Enforces contract via abstract methods
- Type hints possible per handler

**Cons**:
- Cannot use with ThrowingTestEventHandler (already extends MessageHandler)
- More complex than trait

**Not recommended** - trait is simpler and more flexible.

## Recommended Action

**Implement Solution 1 (Trait-Based Approach)**

## Technical Details

**Affected Files**:
- `tests/Functional/Fixtures/TestEventHandler.php` (refactor)
- `tests/Functional/Fixtures/OrderPlacedHandler.php` (refactor)
- `tests/Functional/Fixtures/ThrowingTestEventHandler.php` (refactor)
- `tests/Functional/Fixtures/TrackableHandlerTrait.php` (new)

**Code Reduction**: ~80 lines (57% reduction in handler code)

**Breaking Changes**: None (public API unchanged)

## Acceptance Criteria

- [ ] `TrackableHandlerTrait.php` created with all tracking methods
- [ ] All 3 handlers refactored to use trait
- [ ] All existing tests still pass
- [ ] Handler public API unchanged (backward compatible)
- [ ] PHPStan passes (no type errors with mixed)
- [ ] Code reduction of ~80 lines achieved

## Work Log

_No work done yet_

## Resources

- **Review**: Code Simplicity Review section "Handler Duplication"
- **Related**: Pattern Recognition Review identified this as anti-pattern
- **Files**: `tests/Functional/Fixtures/*.php`
