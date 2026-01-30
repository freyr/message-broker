---
status: pending
priority: p3
issue_id: 7
tags: [code-review, cleanup, dead-code, maintainability]
dependencies: []
---

# Remove Dead Code Methods from FunctionalTestCase

## Problem Statement

Two helper methods in `FunctionalTestCase.php` are never used and add unnecessary complexity and maintenance burden.

**Impact**:
- ~62 lines of dead code
- Cognitive overhead ("Should I use this method?")
- False positives in code search/navigation

## Findings

**Code Simplicity Reviewer** identified:

### 1. `consumeFromInboxWithTransaction()` (38 lines, NEVER USED)

**Location**: `FunctionalTestCase.php:260-298`

**Purpose**: Manual transaction wrapping for worker consumption

**Why it exists**: Created during troubleshooting before `doctrine_transaction` middleware was properly configured. Now redundant.

**Current usage**: 0 occurrences (dead code)

### 2. `assertDatabaseHasRecord()` (24 lines, NEVER USED)

**Location**: `FunctionalTestCase.php:154-178`

**Purpose**: Generic database assertion with binary UUID handling

**Why it exists**: Early helper attempt, but specialized methods like `assertDeduplicationEntryExists()` are more appropriate.

**Current usage**: 0 occurrences in new tests

## Proposed Solutions

### Solution 1: Delete Immediately (RECOMMENDED)

**Effort**: Trivial (5 minutes)
**Risk**: None (code is unused)
**Pros**:
- Immediate code reduction
- Removes confusion
- No impact on tests

**Cons**: None

**Implementation**:
```bash
# Delete lines 154-178 (assertDatabaseHasRecord)
# Delete lines 260-298 (consumeFromInboxWithTransaction)
```

### Solution 2: Mark as @deprecated

**Effort**: Trivial (5 minutes)
**Risk**: Low
**Pros**:
- Documents intention without immediate removal

**Cons**:
- Still creates confusion
- Delays inevitable deletion

**Not recommended** - there's no reason to keep dead code

### Solution 3: Keep for "Future Use"

**Not recommended** - YAGNI principle violation

## Recommended Action

**Delete both methods immediately** (Solution 1)

## Technical Details

**Affected Files**:
- `tests/Functional/FunctionalTestCase.php` (delete 62 lines total)

**Lines to Delete**:
- Lines 154-178: `assertDatabaseHasRecord()` method
- Lines 260-298: `consumeFromInboxWithTransaction()` method

**Breaking Changes**: None (methods were never used externally)

**Code Reduction**: 62 lines (13% reduction in FunctionalTestCase)

## Acceptance Criteria

- [ ] `consumeFromInboxWithTransaction()` method deleted
- [ ] `assertDatabaseHasRecord()` method deleted
- [ ] All tests still pass
- [ ] No grep matches for method names (verify truly unused)
- [ ] PHPStan passes (no undefined method errors)
- [ ] Code reduction of 62 lines achieved

## Work Log

_No work done yet_

## Resources

- **Review**: Code Simplicity Review - "Dead Code" section
- **Verification**: `grep -r "consumeFromInboxWithTransaction\|assertDatabaseHasRecord" tests/`
- **File**: `tests/Functional/FunctionalTestCase.php`
