---
status: complete
priority: p1
issue_id: "006"
tags: [code-review, duplication, tests, functional-tests]
dependencies: ["005"]
---

# Remove quadruplicated deduplication test methods

## Problem Statement

The "publish duplicate message, assert handler invoked once" scenario is tested in **four** separate test classes. This creates maintenance burden, slows the test suite (each instance boots kernel + AMQP + DB), and obscures which test is the canonical one.

## Findings

- **Pattern Recognition Agent**: Identified 4 copies of the same test across `InboxFlowTest`, `InboxDeduplicationOnlyTest`, `InboxDeduplicationEdgeCasesTest`, and `InboxConcurrentProcessingTest`
- **Simplicity Agent**: Confirmed all four are functionally identical with only cosmetic differences (variable names, event payloads)
- **Performance Agent**: `InboxConcurrentProcessingTest` is misleadingly named — admits "sequential processing (not true parallelism)" in docblock; `testTwoWorkersProcessDistinctMessages` uses one worker, not two

## Duplicate Locations

| File | Method | Unique? |
|------|--------|---------|
| `InboxFlowTest.php:60` | `testDuplicateMessageIsNotProcessedTwice` | **KEEP** (canonical) |
| `InboxDeduplicationOnlyTest.php:45` | `testEndToEndDeduplication` | Duplicate |
| `InboxDeduplicationEdgeCasesTest.php:107` | `testDuplicateMessageDuringFirstProcessingIsDetected` | Duplicate |
| `InboxConcurrentProcessingTest.php:80` | `testDuplicateMessageIsSkippedBySecondWorker` | Duplicate |

Additionally, `InboxConcurrentProcessingTest::testTwoWorkersProcessDistinctMessages` (line 30) processes 10 messages sequentially with one worker — not a concurrency test.

## Proposed Solutions

### Option A: Delete `InboxConcurrentProcessingTest` entirely + remove duplicate methods (Recommended)

- Delete `tests/Functional/InboxConcurrentProcessingTest.php` (entire file — both tests are either duplicates or misleading)
- Remove `InboxDeduplicationOnlyTest::testEndToEndDeduplication` (keep `testDeduplicationStoreDirectly` if it has value, or move to unit tests)
- Remove `InboxDeduplicationEdgeCasesTest::testDuplicateMessageDuringFirstProcessingIsDetected`
- Keep `InboxFlowTest::testDuplicateMessageIsNotProcessedTwice` as the canonical dedup test

**Pros:** ~214 LOC removed, clearer test ownership, faster suite
**Cons:** None — no unique coverage lost
**Effort:** Small
**Risk:** None

### Option B: Rename + deduplicate

Keep `InboxConcurrentProcessingTest` but rename to `InboxBatchProcessingTest` and rewrite tests to have a unique purpose.

**Pros:** Preserves test count
**Cons:** More effort for little value; sequential batch processing of 10 messages proves nothing beyond 1
**Effort:** Medium
**Risk:** Low

## Acceptance Criteria

- [ ] Only one canonical deduplication test remains
- [ ] `InboxConcurrentProcessingTest.php` deleted or repurposed
- [ ] `InboxFlowTest::testDuplicateMessageIsNotProcessedTwice` is the canonical dedup test
- [ ] All remaining tests pass
- [ ] `InboxDeduplicationOnlyTest` either deleted or contains only `testDeduplicationStoreDirectly`

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-14 | Created | Found during functional test review (5 agents) |
