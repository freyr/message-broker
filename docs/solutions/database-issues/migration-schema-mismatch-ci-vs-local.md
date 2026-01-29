---
title: "CI workflow failing with MySQL schema mismatch - messenger_outbox using BINARY(16) instead of BIGINT"
category: database-issues
tags:
  - mysql
  - ci-cd
  - schema
  - doctrine
  - symfony-messenger
  - migration
  - auto-increment
module: Database Schema
symptom: "SQLSTATE[HY000]: General error: 1364 Field 'id' doesn't have a default value"
root_cause: "Migration file schema did not match actual working local database schema for messenger_outbox table"
severity: high
date_encountered: 2026-01-30
resolved: true
resolution_commit: 44a10bed50d37bc646cffce6cb24e050ee6d3aa2
---

# CI Workflow Failing with MySQL Schema Mismatch

## Problem Statement

**Error in CI:**
```
SQLSTATE[HY000]: General error: 1364 Field 'id' doesn't have a default value
```

**Behavior:**
- ❌ **CI workflow failed** with database error during INSERT operations to `messenger_outbox` table
- ✅ **Local tests passed** successfully
- 🤔 **Both environments running MySQL 8.0**

**Context:**
The error occurred when the Symfony Messenger Doctrine outbox transport tried to insert messages into the `messenger_outbox` table. CI was using a fresh database created from `migrations/schema.sql`, while local tests were running against a long-lived Docker container with a manually corrected schema.

---

## Investigation Journey

### Step 1: Initial Hypothesis - MySQL Version Mismatch ❌

**Assumption:** CI might be using different MySQL version (9.1) vs local (8.0)

**Investigation:**
- Checked `.github/workflows/tests.yml` - initially had `mysql:9.1`
- Checked `compose.yaml` - had `mysql:8.0`
- Updated CI to use MySQL 8.0

**Result:** ❌ Tests still failed with same error

**Why this failed:** The error wasn't related to MySQL version differences, but to schema differences between environments.

---

### Step 2: Fresh Database Theory ✅

**Key Insight from User:**
> "Local container running long time, check actual schema vs migration file"

**Hypothesis:** The local database schema might have evolved differently than what the migration file creates in CI.

**Why this was important:**
- **Local container:** Long-lived, schema potentially modified manually or through testing
- **CI environment:** Fresh database created from `migrations/schema.sql` every run
- **If they differ:** Tests would pass locally but fail in CI

---

### Step 3: Schema Comparison - THE BREAKTHROUGH ✅

**Compared:**
1. Migration file (`migrations/schema.sql`)
2. Actual local database schema (using `SHOW CREATE TABLE`)

**Discovery:**

| Component | Migration File (CI) | Local Database | Status |
|-----------|-------------------|----------------|--------|
| `messenger_outbox.id` | `BINARY(16)` with `(DC2Type:id_binary)` | `BIGINT AUTO_INCREMENT` | ❌ **MISMATCH** |
| `message_broker_deduplication.message_id` | `BINARY(16)` | `BINARY(16)` | ✅ Match |
| `messenger_messages.id` | `BIGINT AUTO_INCREMENT` | `BIGINT AUTO_INCREMENT` | ✅ Match |

**Original Migration File (Wrong):**
```sql
-- 1. messenger_outbox
CREATE TABLE messenger_outbox (
    id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',  -- ❌ WRONG
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX idx_queue_name (queue_name),
    INDEX idx_available_at (available_at),
    INDEX idx_delivered_at (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Actual Local Database (Correct):**
```sql
CREATE TABLE `messenger_outbox` (
  `id` bigint NOT NULL AUTO_INCREMENT,  -- ✅ CORRECT
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `headers` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `available_at` datetime NOT NULL,
  `delivered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_queue_name` (`queue_name`),
  KEY `idx_available_at` (`available_at`),
  KEY `idx_delivered_at` (`delivered_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

---

## Root Cause Analysis

### Why BINARY(16) Failed in CI

**The Error:**
```
Field 'id' doesn't have a default value
```

**What Happened:**
1. CI created table with `id BINARY(16) NOT NULL PRIMARY KEY` (no AUTO_INCREMENT, no DEFAULT)
2. Symfony Messenger Doctrine Transport attempted: `INSERT INTO messenger_outbox (body, headers, queue_name, ...) VALUES (...)`
3. MySQL rejected the INSERT because `id` field is NOT NULL but has no value and no default
4. Test failed with the error above

**Why Local Tests Passed:**
- Local database had `id BIGINT AUTO_INCREMENT`
- MySQL automatically generated sequential IDs (1, 2, 3, ...)
- No error occurred

---

### Why the Migration File Was Wrong

**The Confusion:**
The project's global `CLAUDE.md` states:
> "all primary keys in mysql database should be by default stored as binary uuid v7"

**The Reality:**
This is a **guideline for custom application tables**, NOT for Symfony Messenger transport tables.

**Symfony Messenger Doctrine Transport Requirements:**
- **Hardcoded assumption:** ID column is auto-incrementing integer
- **Source:** `vendor/symfony/messenger/Transport/Doctrine/Connection.php`
- **Behavior:**
  - Does NOT provide ID values in INSERT statements
  - Relies on MySQL AUTO_INCREMENT to generate IDs
  - Fetches last inserted ID using `$connection->lastInsertId()`

**3-Table Architecture Truth:**

| Table | Purpose | ID Type | Why |
|-------|---------|---------|-----|
| `messenger_outbox` | Symfony transport | `BIGINT AUTO_INCREMENT` | **Symfony requirement** |
| `message_broker_deduplication` | Custom deduplication | `BINARY(16)` | **Application-controlled** |
| `messenger_messages` | Symfony transport | `BIGINT AUTO_INCREMENT` | **Symfony requirement** |

**Root Cause:**
The migration file was created with `BINARY(16)` for `messenger_outbox.id` following the project's UUID guideline, but this violated Symfony Messenger's transport requirements. The local database was corrected manually (or through earlier testing), making local tests pass, but the migration file remained incorrect, causing CI failures.

---

## Working Solution

**Fixed Migration File:**

```sql
-- 1. messenger_outbox
-- Purpose: Stores domain events for transactional outbox pattern
-- Note: Uses BIGINT AUTO_INCREMENT (Symfony Messenger Doctrine transport requirement)
CREATE TABLE messenger_outbox (
    id BIGINT AUTO_INCREMENT NOT NULL PRIMARY KEY,  -- ✅ CORRECTED
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX idx_queue_name (queue_name),
    INDEX idx_available_at (available_at),
    INDEX idx_delivered_at (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Key Changes:**
1. Changed `id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)'`
2. To `id BIGINT AUTO_INCREMENT NOT NULL PRIMARY KEY`
3. Added explanatory comment about Symfony requirement
4. Removed Doctrine type hint (no longer needed)

**Commit Details:**
- **Commit:** `44a10be`
- **Message:** "fix(db): correct messenger_outbox schema to use BIGINT AUTO_INCREMENT"
- **File:** `migrations/schema.sql`

---

## Verification

### How to Confirm the Fix Works

**1. Verify Schema Using SHOW CREATE TABLE:**
```bash
# Local database
docker compose exec mysql mysql -u messenger -pmessenger messenger_test \
  -e "SHOW CREATE TABLE messenger_outbox\G"

# Expected output:
# `id` bigint NOT NULL AUTO_INCREMENT
```

**2. CI Verification (Primary):**
- Push the commit with the schema fix
- GitHub Actions workflow will:
  1. Create fresh MySQL 8.0 container
  2. Run `migrations/schema.sql`
  3. Execute tests
- Tests should now pass in CI ✅

**3. Fresh Database Test Locally:**
```bash
# Drop and recreate database from migration file
docker compose exec mysql mysql -u root -proot \
  -e "DROP DATABASE IF EXISTS messenger_test; CREATE DATABASE messenger_test;"

docker compose exec mysql mysql -u root -proot messenger_test < migrations/schema.sql

# Run tests
docker compose run --rm php vendor/bin/phpunit --testdox
```

---

## Prevention Strategies

### 1. Schema Validation in CI

Add schema validation step to ensure migration files create correct schema:

```yaml
# .github/workflows/tests.yml
- name: Validate database schema
  run: |
    # Verify messenger_outbox has BIGINT AUTO_INCREMENT
    mysql -h 127.0.0.1 -P 3308 -u messenger -pmessenger messenger_test \
      -e "SHOW CREATE TABLE messenger_outbox" | grep -q "bigint.*AUTO_INCREMENT"
```

### 2. Documentation Guidelines

Create clear decision matrix for when to use BIGINT vs BINARY(16):

**Rule:**
- **Symfony Messenger transport tables:** MUST use `BIGINT AUTO_INCREMENT`
- **Custom application tables:** CAN use `BINARY(16)` UUID v7

**Tables:**
- `messenger_outbox` - BIGINT AUTO_INCREMENT (Symfony requirement)
- `messenger_messages` - BIGINT AUTO_INCREMENT (Symfony requirement)
- `message_broker_deduplication` - BINARY(16) (custom table, follows UUID convention)

### 3. Development Practices

**Use `SHOW CREATE TABLE` to validate:**
```bash
# Always compare migration file to actual schema
docker compose exec mysql mysql -u messenger -pmessenger messenger_test \
  -e "SHOW CREATE TABLE messenger_outbox\G" > actual_schema.sql

# Compare to migrations/schema.sql
diff migrations/schema.sql actual_schema.sql
```

**Periodically reset local database:**
```bash
# Recreate database from migration file to catch drift
docker compose exec mysql mysql -u root -proot \
  -e "DROP DATABASE messenger_test; CREATE DATABASE messenger_test;"

docker compose exec mysql mysql -u root -proot messenger_test < migrations/schema.sql
```

### 4. Testing Strategies

**Schema validation tests:**
```php
// tests/Integration/SchemaValidationTest.php
public function testMessengerOutboxSchemaIsCorrect(): void
{
    $this->assertTableExists('messenger_outbox');
    $this->assertColumnType('messenger_outbox', 'id', 'bigint');
    $this->assertColumnIsAutoIncrement('messenger_outbox', 'id');
}

public function testDeduplicationSchemaIsCorrect(): void
{
    $this->assertTableExists('message_broker_deduplication');
    $this->assertColumnType('message_broker_deduplication', 'message_id', 'binary');
    $this->assertColumnLength('message_broker_deduplication', 'message_id', 16);
}
```

---

## Key Learnings

1. **Environment Parity:** Long-lived local containers can drift from migration files, causing CI mismatches
2. **Framework Requirements Trump Guidelines:** Symfony Messenger requires AUTO_INCREMENT IDs, even when project guidelines prefer UUIDs
3. **3-Table Architecture Rationale:**
   - Symfony transport tables: Follow Symfony conventions (BIGINT AUTO_INCREMENT)
   - Custom application tables: Follow project conventions (BINARY(16) UUID v7)
4. **Fresh Database Testing:** Periodically recreate local databases from migration files to catch drift
5. **Migration File as Source of Truth:** CI uses migration files, so they must match working reality
6. **Use SHOW CREATE TABLE:** Always validate migration files against actual database schema

---

## Related Issues & References

### Documentation
- `docs/database-schema.md` - Database schema documentation (already correct)
- `CLAUDE.md` - Lines 761-762 (mentions messenger_outbox uses BINARY - needs correction)
- `README.md` - Database setup instructions

### Related Solutions
- `docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md` - Related test environment configuration issue

### Related Commits
- `44a10be` - fix(db): correct messenger_outbox schema to use BIGINT AUTO_INCREMENT
- `fc558c7` - fix(ci): prevent duplicate workflow runs on PR branches
- `b4f77a9` - fix(ci): align MySQL and RabbitMQ versions with local environment

### Files Modified
- `/Users/michal/code/freyr/message-broker/migrations/schema.sql`
- `/Users/michal/code/freyr/message-broker/.github/workflows/tests.yml`

---

## Quick Reference

### Minimal Working Schema

```sql
-- Symfony Messenger transport tables: BIGINT AUTO_INCREMENT
CREATE TABLE messenger_outbox (
    id BIGINT AUTO_INCREMENT NOT NULL PRIMARY KEY,
    -- ... rest of columns
) ENGINE=InnoDB;

CREATE TABLE messenger_messages (
    id BIGINT AUTO_INCREMENT NOT NULL PRIMARY KEY,
    -- ... rest of columns
) ENGINE=InnoDB;

-- Custom tables: BINARY(16) UUID v7
CREATE TABLE message_broker_deduplication (
    message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    -- ... rest of columns
) ENGINE=InnoDB;
```

### Command to Check Schema

```bash
# Verify messenger_outbox schema
docker compose exec mysql mysql -u messenger -pmessenger messenger_test \
  -e "SHOW CREATE TABLE messenger_outbox\G"

# Should output:
# `id` bigint NOT NULL AUTO_INCREMENT
```

---

## Summary

**Problem:** Migration file had BINARY(16) for messenger_outbox.id, violating Symfony Messenger transport requirements

**Cause:** Project UUID v7 convention applied incorrectly to Symfony framework table

**Solution:** Corrected migration file to use BIGINT AUTO_INCREMENT for Symfony transport tables

**Verification:** CI tests now pass with fresh database created from corrected migration file

**Prevention:**
- Document Symfony transport table requirements clearly
- Add schema validation tests
- Use `SHOW CREATE TABLE` to validate migration files
- Periodically reset local database from migration files
