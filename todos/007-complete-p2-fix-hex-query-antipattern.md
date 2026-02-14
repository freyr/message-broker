---
status: complete
priority: p2
issue_id: "007"
tags: [code-review, performance, tests, sql, functional-tests]
dependencies: []
---

# Fix HEX() function on indexed column in test helpers

## Problem Statement

`FunctionalTestCase::assertDeduplicationEntryExists()` and `assertNoDeduplicationEntryExists()` use `WHERE HEX(message_id) = ?` which applies a function to the primary key column, forcing a full table scan on every call. While the table is small in tests, this is a bad pattern that could be copied into production code.

## Findings

- **Performance Agent**: Identified as CRITICAL pattern — `assertDeduplicationEntryExists()` called 10 times in a loop in `testTwoWorkersProcessDistinctMessages`; each call triggers full table scan
- **Security Agent**: Related — the `getTableRowCount()` method also uses string interpolation for table names (mitigated by allowlist but fragile)

## Affected Locations

| File | Line | Method |
|------|------|--------|
| `FunctionalTestCase.php` | 471 | `assertDeduplicationEntryExists()` |
| `FunctionalTestCase.php` | 509 | `assertNoDeduplicationEntryExists()` |
| `TransactionBehaviorTest.php` | 48 | Direct `HEX()` query (file may be deleted per todo 005) |

## Proposed Solutions

### Option A: Use binary comparison (Recommended)

Convert UUID string to binary in PHP and compare directly:

```php
$messageIdBinary = hex2bin(str_replace('-', '', $messageId));
$count = $connection->fetchOne(
    'SELECT COUNT(*) FROM message_broker_deduplication WHERE message_id = ?',
    [$messageIdBinary],
    [ParameterType::BINARY]
);
```

**Pros:** Uses primary key index (O(log n) vs O(n)), correct pattern
**Cons:** Minor change
**Effort:** Small
**Risk:** None

## Acceptance Criteria

- [ ] `assertDeduplicationEntryExists()` uses binary comparison
- [ ] `assertNoDeduplicationEntryExists()` uses binary comparison
- [ ] All dedup-related tests still pass

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-14 | Created | Found during functional test review (performance agent) |
