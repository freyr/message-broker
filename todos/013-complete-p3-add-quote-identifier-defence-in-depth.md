---
status: complete
priority: p3
issue_id: "013"
tags: [code-review, security, tests, functional-tests]
dependencies: []
---

# Add quoteIdentifier() defence-in-depth to getTableRowCount()

## Problem Statement

`FunctionalTestCase::getTableRowCount()` interpolates the table name directly into SQL: `"SELECT COUNT(*) FROM {$table}"`. While mitigated by an `ALLOWED_TABLES` allowlist, this pattern is fragile — if the allowlist were removed during refactoring, it becomes a SQL injection vector.

## Findings

- **Security Agent**: Rated Medium severity — "the pattern itself is dangerous... defence in depth"
- Note: If todo 008 removes the `ALLOWED_TABLES` guard (YAGNI), this fix becomes more important

## Proposed Solutions

### Option A: Use quoteIdentifier() (Recommended)

```php
$count = $connection->fetchOne(
    sprintf('SELECT COUNT(*) FROM %s', $connection->quoteIdentifier($table))
);
```

**Pros:** Defence in depth, one-line change
**Cons:** Minor
**Effort:** Small
**Risk:** None

## Acceptance Criteria

- [ ] Table name quoted via `quoteIdentifier()` in `getTableRowCount()`
- [ ] Tests pass

## Work Log

| Date | Action | Notes |
|------|--------|-------|
| 2026-02-14 | Created | Found during functional test review (security agent) |
