---
status: complete
priority: p2
issue_id: "009"
tags: [code-review, performance, tests, functional-tests]
dependencies: ["005", "006"]
---

# Optimise functional test setup performance

## Problem Statement

The test suite has several measurable inefficiencies in `setUp()` that add unnecessary database and AMQP round trips:

1. `cleanDatabase()` calls `tablesExist()` 3 times per test (60 unnecessary metadata queries across suite)
2. `setupAmqp()` re-declares exchange + queues before every test (200+ unnecessary AMQP frames)
3. Schema initialisation runs 9 times (once per test class) instead of once per suite
4. `FOREIGN_KEY_CHECKS` toggled despite no foreign keys existing

## Findings

- **Performance Agent**: Identified all four as CRITICAL inefficiencies with projected savings of ~60 metadata queries + ~200 AMQP frames + 8 redundant DDL executions per suite run
- **Architecture Agent**: Noted schema `$schemaInitialized` is per-class due to PHP static inheritance, causing 9x execution

## Proposed Solutions

### Option A: Incremental fixes (Recommended)

1. Remove `tablesExist()` guards — tables guaranteed to exist after `setUpBeforeClass()`
2. Move AMQP `exchange_declare`/`queue_declare`/`queue_bind` to one-time setup; keep only `queue_purge` in `setUp()`
3. Remove `SET FOREIGN_KEY_CHECKS` toggle (no FK constraints exist)
4. Fix schema init to run once per suite (use bootstrap or file sentinel)

**Pros:** Estimated ~1-2 seconds saved per suite run, cleaner code
**Cons:** One-time schema init requires coordination (bootstrap approach)
**Effort:** Medium
**Risk:** Low — all changes are test infrastructure only

### Option B: Minimal — just remove guards

Only remove `tablesExist()` guards and `FOREIGN_KEY_CHECKS`. Leave AMQP and schema init as-is.

**Pros:** Simplest change
**Cons:** Misses the bigger wins
**Effort:** Small
**Risk:** None

## Acceptance Criteria

- [ ] `cleanDatabase()` does not call `tablesExist()`
- [ ] `FOREIGN_KEY_CHECKS` toggle removed
- [ ] AMQP infrastructure declared once, not per-test
- [ ] All tests pass with same results

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-14 | Created | Found during functional test review (performance agent) |
