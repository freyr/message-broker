---
status: complete
priority: p2
issue_id: "011"
tags: [code-review, test-coverage, tests, functional-tests]
dependencies: []
---

# Add test coverage for DeduplicationStoreCleanup command

## Problem Statement

`src/Command/DeduplicationStoreCleanup.php` performs destructive `DELETE` operations against the deduplication table in production and has **zero test coverage** — neither functional nor unit. This is a production-facing console command that deletes data based on a configurable `--days` option.

## Findings

- **Architecture Agent**: Identified as highest-priority coverage gap — "This is a production-facing command that deletes data"
- No other agent flagged this as it is a missing test, not a problem with existing tests

## What Needs Testing

1. Records older than N days are deleted
2. Records newer than N days are preserved
3. Command returns SUCCESS exit code
4. Output reports correct deletion count
5. Edge case: empty table (no records to delete)
6. Edge case: `--days=0` behaviour

## Proposed Solutions

### Option A: Add functional test (Recommended)

Create `tests/Functional/Command/DeduplicationStoreCleanupTest.php` that:
- Inserts dedup records with different timestamps
- Runs the command with `--days=7`
- Asserts only old records were deleted

**Pros:** Full confidence the command works correctly
**Cons:** Requires database
**Effort:** Small-Medium
**Risk:** None

### Option B: Add unit test with mocked connection

Mock the DBAL connection and verify the correct SQL is generated.

**Pros:** No database dependency
**Cons:** Less confidence in real behaviour
**Effort:** Small
**Risk:** Low

## Acceptance Criteria

- [ ] Test exists for `DeduplicationStoreCleanup` command
- [ ] Verifies old records deleted and new records preserved
- [ ] Verifies command output and exit code

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-14 | Created | Found during functional test review (architecture agent) |
