---
status: complete
priority: p1
issue_id: "005"
tags: [code-review, dead-code, tests, functional-tests]
dependencies: []
---

# Delete dead/debug test files and Scripts directory

## Problem Statement

Five functional test files and the entire `Scripts/` directory are dead code — debug investigation artefacts, always-incomplete tests, or 100% duplicates of existing tests. They add ~540 LOC of maintenance burden and noise to test reports without providing any unique coverage.

## Findings

- **Pattern Recognition Agent**: Identified `TransactionBehaviorTest` always calls `markTestIncomplete()` (both branches); `InboxHeaderDebugTest` and `InboxSerializerDebugTest` have "Debug" in name and duplicate existing coverage; Scripts/ are ad-hoc investigation tools
- **Architecture Agent**: Confirmed `TransactionBehaviorTest` duplicates `InboxTransactionRollbackTest`; `InboxSerializerDebugTest` is a unit test in functional clothing; `InboxDeserializationTest` is a strict subset of `InboxFlowTest`
- **Simplicity Agent**: Independently confirmed all five files + Scripts/ are removable with zero loss of unique coverage
- **Performance Agent**: `TransactionBehaviorTest` adds full setup cost for no regression value; `InboxSerializerDebugTest` boots AMQP infrastructure despite never touching AMQP
- **Security Agent**: Scripts lack `_test` database safety check; `test_basic_dedup.php` has hardcoded hostname `'mysql'` (copy-paste error)

## Files to Delete

| File | LOC | Reason |
|------|-----|--------|
| `tests/Functional/TransactionBehaviorTest.php` | 69 | Always `markTestIncomplete()`, duplicates `InboxTransactionRollbackTest` |
| `tests/Functional/InboxHeaderDebugTest.php` | 55 | Debug investigation test, subset of `OutboxFlowTest` |
| `tests/Functional/InboxSerializerDebugTest.php` | 49 | Debug test, unit-test scope, covered by `InboxFlowTest` |
| `tests/Functional/InboxDeserializationTest.php` | 77 | 100% duplicate of `InboxFlowTest::testSemanticNameTranslation` + `testMessageFormatCorrectness` |
| `tests/Functional/Scripts/` (4 files) | 290 | Ad-hoc investigation scripts, all covered by PHPUnit tests |

## Proposed Solutions

### Option A: Delete all at once (Recommended)

**Pros:** Clean, immediate reduction of 540 LOC. No risk — all behaviour is covered by remaining tests.
**Cons:** None.
**Effort:** Small
**Risk:** None — run full test suite to confirm

## Acceptance Criteria

- [ ] All 5 files and Scripts/ directory deleted
- [ ] All remaining tests pass
- [ ] No references to deleted files remain in codebase

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-14 | Created | Found during functional test review (5 agents) |

## Resources

- `InboxTransactionRollbackTest` covers `TransactionBehaviorTest` scenarios
- `InboxFlowTest` covers `InboxDeserializationTest` and `InboxSerializerDebugTest` scenarios
- `OutboxFlowTest::testOutboxPublishesToAmqp` covers `InboxHeaderDebugTest` scenarios
