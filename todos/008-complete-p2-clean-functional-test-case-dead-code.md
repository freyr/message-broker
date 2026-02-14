---
status: complete
priority: p2
issue_id: "008"
tags: [code-review, dead-code, tests, functional-tests]
dependencies: ["005", "006"]
---

# Clean up dead helper methods in FunctionalTestCase

## Problem Statement

`FunctionalTestCase` contains three unused helper methods (~83 LOC) and one over-engineered method with 3 of 4 options never used. Additionally, the `ALLOWED_TABLES` SQL injection guard is YAGNI for test code.

## Findings

- **Simplicity Agent**: Identified `publishTestEvent()`, `publishOrderPlacedEvent()`, and `assertMessageInFailedTransport()` as dead code — never called by any test
- **Pattern Recognition Agent**: Confirmed `publishTestEvent()`/`publishOrderPlacedEvent()` are "speculative generality" anti-pattern — written in anticipation of use, never adopted
- **Performance Agent**: `publishToAmqp()` re-declares queues on every call (redundant since `setupAmqp()` already declares them)

## Dead Code to Remove

| Method | Lines | Reason |
|--------|-------|--------|
| `publishTestEvent()` | 340-359 | Never called |
| `publishOrderPlacedEvent()` | 370-390 | Never called |
| `assertMessageInFailedTransport()` | 529-560 | Never called |
| `publishMalformedAmqpMessage()` unused options | 603-638 | Only `invalidUuid` used; `missingType`, `missingMessageId`, `invalidJson` never used |
| `ALLOWED_TABLES` constant + guard in `getTableRowCount()` | 565, 574-580 | YAGNI — test helpers don't need SQL injection protection |

## Proposed Solutions

### Option A: Delete dead methods, simplify others (Recommended)

- Delete 3 unused methods
- Simplify `publishMalformedAmqpMessage()` to only support the one case used, or inline it
- Remove `ALLOWED_TABLES` guard
- Remove redundant `queue_declare` in `publishToAmqp()` (line 316)

**Pros:** ~115 LOC removed, simpler base class
**Cons:** None
**Effort:** Small
**Risk:** None

## Acceptance Criteria

- [ ] No method in `FunctionalTestCase` is unused
- [ ] `publishToAmqp()` does not re-declare queues
- [ ] All tests pass

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-14 | Created | Found during functional test review (5 agents) |
