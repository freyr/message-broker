---
status: pending
priority: p1
issue_id: 7
tags: [code-review, security, sql-injection, test-infrastructure]
dependencies: []
---

# Fix SQL Injection Vulnerability in getTableRowCount()

## Problem Statement

The `getTableRowCount()` helper method in test infrastructure accepts unsanitized table name and interpolates it directly into SQL query, creating SQL injection risk.

**Impact**:
- **Security vulnerability** (medium severity - test code only)
- **Sets bad precedent** that developers might copy to production code
- **Risk of accidental data corruption** if malicious table name passed

## Findings

**Security Sentinel Review** identified SQL injection via dynamic table names:

**Location**: `FunctionalTestCase.php:427`

**Vulnerable Code**:
```php
protected function getTableRowCount(string $table): int
{
    return (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table}");
}
```

**Attack Vector**:
```php
// Hypothetical malicious usage:
$this->getTableRowCount("users; DROP TABLE users--");
// Executes: SELECT COUNT(*) FROM users; DROP TABLE users--
```

**Current Usage**: Only called with literal strings in tests, but still vulnerable to mistakes.

## Proposed Solutions

### Solution 1: Table Name Whitelist (RECOMMENDED)

**Effort**: Small (15 minutes)
**Risk**: Very Low
**Pros**:
- Explicit validation
- Clear error messages
- Prevents typos

**Cons**:
- Requires updating whitelist if new tables added

**Implementation**:
```php
private const ALLOWED_TABLES = [
    'message_broker_deduplication',
    'messenger_outbox',
    'messenger_messages',
];

protected function getTableRowCount(string $table): int
{
    if (!in_array($table, self::ALLOWED_TABLES, strict: true)) {
        throw new \InvalidArgumentException(
            sprintf('Invalid table name: "%s". Allowed tables: %s',
                $table,
                implode(', ', self::ALLOWED_TABLES)
            )
        );
    }

    return (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table}");
}
```

### Solution 2: Identifier Quoting

**Effort**: Small (10 minutes)
**Risk**: Low
**Pros**:
- Works with any table name
- No whitelist maintenance

**Cons**:
- Doesn't prevent typos
- Less explicit about allowed tables

**Implementation**:
```php
protected function getTableRowCount(string $table): int
{
    $quotedTable = $connection->quoteIdentifier($table);
    return (int) $connection->fetchOne("SELECT COUNT(*) FROM {$quotedTable}");
}
```

### Solution 3: Combine Both Approaches

**Most secure** - whitelist + quoting

## Recommended Action

**Implement Solution 1 (Whitelist)** - Explicit is better than implicit for test infrastructure.

## Technical Details

**Affected Files**:
- `tests/Functional/FunctionalTestCase.php` (modify `getTableRowCount()`)

**Current Usages**:
```bash
grep -r "getTableRowCount" tests/
# Returns ~10 usages, all with literal strings (currently safe)
```

**Breaking Changes**: None if existing tests use valid table names

## Acceptance Criteria

- [ ] `ALLOWED_TABLES` constant added to FunctionalTestCase
- [ ] `getTableRowCount()` validates table name against whitelist
- [ ] Throws `InvalidArgumentException` for invalid table names
- [ ] All existing tests still pass (use valid table names)
- [ ] Test added: `testGetTableRowCountThrowsForInvalidTable()`
- [ ] PHPStan passes
- [ ] Security review confirms SQL injection fixed

## Work Log

_No work done yet_

## Resources

- **Review**: Security Sentinel Review - Finding 1 "SQL Injection via Dynamic Table Names"
- **File**: `tests/Functional/FunctionalTestCase.php:427`
- **OWASP**: https://owasp.org/www-community/attacks/SQL_Injection
