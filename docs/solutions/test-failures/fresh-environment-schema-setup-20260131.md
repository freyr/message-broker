---
module: Testing Infrastructure
date: 2026-01-31
problem_type: test_failure
component: testing_framework
symptoms:
  - "Table 'messenger_test.message_broker_deduplication' doesn't exist"
  - "Tests pass locally but fail in CI with fresh database"
  - "docker compose down -v causes all tests to fail with TableNotFoundException"
root_cause: incomplete_setup
rails_version: 7.3.0
resolution_type: environment_setup
severity: high
tags: [database-setup, test-environment, ci-cd, docker, fresh-install, schema-migration]
---

# Troubleshooting: Fresh Test Environment Database Schema Setup Failure

## Problem

Tests fail with "Base table or view not found" errors when running in fresh test environments (clean CI builds or local environments after `docker compose down -v`). The issue occurs because database tables are not created before tests run, even though schema setup appears to be configured.

## Environment

- Module: Testing Infrastructure (Message Broker Package)
- Framework: Symfony Messenger 7.3+ with Doctrine DBAL 3+
- Affected Components: Functional test suite, CI workflow, database schema management
- Database: MySQL 8.0 (messenger_test)
- Date: 2026-01-31

## Symptoms

**CI Environment:**
```
Doctrine\DBAL\Exception\TableNotFoundException:
An exception occurred while executing a query: SQLSTATE[42S02]:
Base table or view not found: 1146 Table 'messenger_test.message_broker_deduplication' doesn't exist

Tests: 34, Assertions: 189, Errors: 7
```

**Local (After `docker compose down -v`):**
```
Doctrine\DBAL\Exception\TableNotFoundException:
Table 'messenger_test.message_broker_deduplication' doesn't exist

Expected handler to be invoked 1 time(s), but was invoked 0 time(s)
Tests: 12, Assertions: 30, Errors: 1, Failures: 6
```

**Additional symptoms:**
- Tests pass on environments with existing database state (local development with reused volumes)
- `TRUNCATE TABLE` fails when table doesn't exist
- Helper methods like `getTableRowCount()` throw exceptions on non-existent tables
- CI workflow shows "Schema setup complete!" but tables aren't actually created

## What Didn't Work

**Attempted Solution 1:** Added table existence checks before truncating in `cleanDatabase()`
```php
// tests/Functional/FunctionalTestCase.php
if ($schemaManager->tablesExist(['message_broker_deduplication'])) {
    $connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');
}
```
- **Why it failed:** This fixed the immediate error in `cleanDatabase()` but didn't address the root cause—tables were never created in the first place. Tests still failed when trying to actually use the tables.

**Attempted Solution 2:** Removed `continue-on-error` from CI schema setup step and added verification
```yaml
# .github/workflows/tests.yml
- name: Setup database schema
  run: |
    mysql ... < migrations/schema.sql
    mysql ... -e "SHOW TABLES;" | grep message_broker_deduplication
```
- **Why it failed:** CI step was creating tables, but the schema file only contained the deduplication table (not messenger tables). This created environment inconsistency between CI (which ran mysql directly) and local (which didn't run any schema setup).

**Attempted Solution 3:** Added existence checks in test helper methods
```php
protected function getTableRowCount(string $table): int
{
    if (!$schemaManager->tablesExist([$table])) {
        return 0;
    }
    return (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table}");
}
```
- **Why it failed:** While this prevented helper methods from crashing, it masked the real issue—tests were running without required database tables, causing subtle failures where handlers weren't invoked or data wasn't persisted.

## Solution

The fix involved **separating test schema from production migration** and **implementing one-time schema setup** in the test suite bootstrap.

### 1. Separate Production Migration from Test Schema

**Production Migration** (`migrations/schema.sql`) - Only deduplication table:
```sql
-- Freyr Message Broker - Production Migration
-- This migration is for production plugin users

-- Message Broker Deduplication Table
-- Application-managed table (not auto-created by Symfony)
-- messenger_outbox and messenger_messages tables are auto-created via auto_setup: true
CREATE TABLE IF NOT EXISTS message_broker_deduplication (
    message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    message_name VARCHAR(255) NOT NULL,
    processed_at DATETIME NOT NULL,
    INDEX idx_message_name (message_name),
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Test Schema** (`tests/Functional/schema.sql`) - All tables with DROP/CREATE:
```sql
-- Freyr Message Broker - Test Environment Schema
-- This schema is ONLY for tests and includes all tables needed for testing

-- Drop tables if they exist (for clean setup)
DROP TABLE IF EXISTS message_broker_deduplication;
DROP TABLE IF EXISTS messenger_outbox;
DROP TABLE IF EXISTS messenger_messages;

-- Message Broker Deduplication Table
CREATE TABLE message_broker_deduplication (
    message_id BINARY(16) NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    message_name VARCHAR(255) NOT NULL,
    processed_at DATETIME NOT NULL,
    INDEX idx_message_name (message_name),
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messenger Outbox Table (pre-created for tests)
CREATE TABLE messenger_outbox (
    id BIGINT AUTO_INCREMENT NOT NULL,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX IDX_75EA56E0FB7336F0 (queue_name),
    INDEX IDX_75EA56E0E3BD61CE (available_at),
    INDEX IDX_75EA56E016BA31DB (delivered_at),
    PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messenger Messages Table (for failed transport, pre-created for tests)
CREATE TABLE messenger_messages (
    id BIGINT AUTO_INCREMENT NOT NULL,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX IDX_75EA56E0FB7336F0 (queue_name),
    INDEX IDX_75EA56E0E3BD61CE (available_at),
    INDEX IDX_75EA56E016BA31DB (delivered_at),
    PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. One-Time Schema Setup in Test Bootstrap

**File: `tests/Functional/FunctionalTestCase.php`**

```php
abstract class FunctionalTestCase extends KernelTestCase
{
    private static bool $schemaInitialized = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Setup database schema once for entire functional test suite
        if (!self::$schemaInitialized) {
            self::setupDatabaseSchema();
            self::$schemaInitialized = true;
        }
    }

    private static function setupDatabaseSchema(): void
    {
        $schemaFile = __DIR__.'/schema.sql';
        $databaseUrl = $_ENV['DATABASE_URL'] ?? 'mysql://messenger:messenger@127.0.0.1:3308/messenger_test';

        // Parse DATABASE_URL
        $parts = parse_url($databaseUrl);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 3306;
        $user = $parts['user'] ?? 'messenger';
        $pass = $parts['pass'] ?? 'messenger';
        $dbname = ltrim($parts['path'] ?? '/messenger_test', '/');

        // SAFETY CHECK: Only run on test databases
        if (!str_contains($dbname, '_test')) {
            throw new \RuntimeException(
                sprintf('SAFETY CHECK FAILED: Database must contain "_test" in name. Got: %s', $dbname)
            );
        }

        // Wait for database to be ready (max 30 seconds)
        $maxRetries = 30;
        $pdo = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $pdo = new \PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $dbname),
                    $user,
                    $pass,
                    [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                    ]
                );
                break;
            } catch (\PDOException $e) {
                if ($i === $maxRetries - 1) {
                    throw new \RuntimeException(
                        sprintf('Failed to connect to database after %d attempts: %s', $maxRetries, $e->getMessage())
                    );
                }
                sleep(1);
            }
        }

        // Read and execute schema file
        $schema = file_get_contents($schemaFile);
        if ($schema === false) {
            throw new \RuntimeException('Failed to read schema file: '.$schemaFile);
        }

        // Execute entire schema file
        $pdo->exec($schema);

        // Verify tables were created
        $stmt = $pdo->query("SHOW TABLES LIKE 'message_broker_deduplication'");
        if ($stmt->fetch() === false) {
            throw new \RuntimeException('Schema applied but message_broker_deduplication table not found');
        }
    }
}
```

### 3. Remove Redundant CI Schema Setup

**File: `.github/workflows/tests.yml`**

```yaml
# REMOVED this step entirely - schema setup now happens in test bootstrap
- name: Setup database schema
  run: |
    mysql -h 127.0.0.1 -P 3308 -u messenger -pmessenger messenger_test < migrations/schema.sql
    # ...

# Now just run tests - schema setup is automatic
- name: Run tests
  env:
    DATABASE_URL: mysql://messenger:messenger@127.0.0.1:3308/messenger_test
    MESSENGER_AMQP_DSN: amqp://guest:guest@127.0.0.1:5673/%2f
    APP_ENV: test
  run: vendor/bin/phpunit --testdox
```

## Why This Works

### Root Cause Analysis

The original issue had multiple contributing factors:

1. **Inconsistent Table Existence Handling**: Test cleanup code had mixed approaches—deduplication table used unconditional `TRUNCATE` (assuming it always exists), whilst auto-managed messenger tables checked existence first.

2. **Silent CI Failures**: GitHub Actions workflow used `continue-on-error: true` on database schema setup step, masking actual schema creation failures and causing confusing downstream test errors.

3. **Environment Parity Problem**: Local environments had tables from previous runs, masking issues that only appeared in clean CI environments or after `docker compose down -v`.

4. **Schema Setup Location**: Running schema setup as a CI-specific step created different behaviour between CI and local environments.

### Why the Solution Addresses Root Cause

1. **Separation of Concerns**:
   - Production migration (`migrations/schema.sql`) contains only what production users need: the deduplication table
   - Test schema (`tests/Functional/schema.sql`) contains everything tests need: all three tables with DROP/CREATE for idempotency
   - This prevents test-specific setup from leaking into production migrations

2. **One-Time Execution**:
   - `setUpBeforeClass()` runs once when the first functional test class loads
   - Static `$schemaInitialized` flag prevents redundant schema application
   - Same mechanism works in both CI and local environments

3. **Database Connection Retry**:
   - Waits up to 30 seconds for database to be ready
   - Handles Docker Compose startup timing issues
   - Works in both fast CI environments and slow local Docker

4. **Safety Checks**:
   - Database name must contain `_test` (prevents accidental production damage)
   - Verifies tables were created after schema execution
   - Fails fast with clear error messages

5. **Environment Parity**:
   - CI and local environments use identical schema setup mechanism
   - No CI-specific steps that could diverge from local behaviour
   - Same timing, same database state, same test behaviour

### Technical Details

The solution leverages PDO's `MYSQL_ATTR_MULTI_STATEMENTS` to execute the entire SQL file as a single batch:

```php
[
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,  // Critical for executing full schema
]
```

This allows the `DROP TABLE IF EXISTS` statements to run before `CREATE TABLE`, ensuring idempotent schema setup regardless of initial database state.

## Prevention

### 1. Fresh Environment Testing Checklist

**Before pushing database or CI changes, always test with a fresh environment:**

```bash
# Full clean slate
docker compose down -v           # Remove all volumes
docker compose up -d mysql rabbitmq
sleep 10                          # Wait for services

# Run tests from scratch
docker compose run --rm php vendor/bin/phpunit --testdox
```

This catches schema setup issues that would otherwise only appear in CI.

### 2. Fail-Fast Philosophy for Infrastructure

**NEVER use `continue-on-error: true` for infrastructure setup:**

```yaml
# ❌ Bad - masks failures
- name: Setup database schema
  continue-on-error: true
  run: mysql ... < migrations/schema.sql

# ✅ Good - fails immediately
- name: Setup database schema
  run: |
    mysql ... < migrations/schema.sql
    # Verify it worked
    mysql ... -e "SHOW TABLES;" | grep required_table || exit 1
```

Only use `continue-on-error` for optional reporting or cleanup steps, never for critical setup.

### 3. Consistent Table Management Patterns

**Apply the same existence-checking pattern across ALL table operations:**

```php
// ✅ Good - consistent pattern
$tables = ['table1', 'table2', 'table3'];
foreach ($tables as $table) {
    if ($schemaManager->tablesExist([$table])) {
        $connection->executeStatement("TRUNCATE TABLE {$table}");
    }
}

// ❌ Bad - mixed patterns
$connection->executeStatement('TRUNCATE TABLE table1');  // Assumes exists
if ($schemaManager->tablesExist(['table2'])) {
    $connection->executeStatement('TRUNCATE TABLE table2');  // Checks first
}
```

### 4. Defensive Test Helpers

**All test helpers should handle non-existent tables gracefully:**

```php
protected function getTableRowCount(string $table): int
{
    // ✅ Always check existence first
    $schemaManager = $connection->createSchemaManager();
    if (!$schemaManager->tablesExist([$table])) {
        return 0;  // Treat non-existent as empty
    }

    return (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table}");
}
```

### 5. Local/CI Environment Parity

**Ensure local and CI environments behave identically:**

- Same database versions (pin versions, don't use `latest`)
- Same credentials, ports, healthchecks
- Same schema setup mechanism (via test bootstrap, not CI-specific steps)
- Same service startup order

```yaml
# ✅ Good - version pinned
services:
  mysql:
    image: mysql:8.0  # Specific version

# ❌ Bad - unpredictable
services:
  mysql:
    image: mysql:latest  # Could change
```

### 6. Architecture Change Impact Checklist

**When changing table management strategies (manual → auto-setup), audit:**

- [ ] Production migration files
- [ ] Test schema files
- [ ] Test cleanup code (`cleanDatabase()`, etc.)
- [ ] Test helper methods (`getTableRowCount()`, etc.)
- [ ] CI workflow schema setup steps
- [ ] Documentation (README, CLAUDE.md, etc.)

### 7. Schema Setup Best Practices

**For Symfony/PHPUnit test suites:**

- Put schema setup in `setUpBeforeClass()` (runs once per test class)
- Use `CREATE TABLE IF NOT EXISTS` or `DROP TABLE IF EXISTS` for idempotency
- Verify critical tables were created
- Include retry logic for database connectivity
- Fail fast with clear error messages

**For test-specific schemas:**

- Separate from production migrations
- Include DROP statements for clean slate
- Pre-create all tables tests need (even if production uses `auto_setup`)
- Document the duplication is intentional

## Related Issues

**Critical Pattern:** This solution has been promoted to Required Reading:
- See: `docs/solutions/patterns/critical-patterns.md` (Pattern #1: Test Environment Schema Setup)

No other related issues documented yet.

---

**Commit History:**
- `bf34b84` - fix(tests): check if deduplication table exists before truncating
- `ff330a8` - fix(ci): ensure database schema setup failures are visible
- `a811170` - fix(tests): check table existence in getTableRowCount helper
- `be7c969` - fix: ensure database schema setup works in fresh test environments

**PR:** Part of #3 - Refactor: enable auto_setup for Doctrine transports
