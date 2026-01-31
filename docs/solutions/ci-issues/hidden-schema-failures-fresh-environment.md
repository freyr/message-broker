---
title: "CI Test Failures: Hidden Schema Setup Failures in Fresh Environments"
date: 2026-01-31
category: ci-issues
tags:
  - ci
  - database-schema
  - github-actions
  - test-failures
  - mysql
  - functional-tests
  - deduplication-table
  - symfony-messenger
  - docker
  - fresh-environment
severity: high
components:
  - GitHub Actions CI workflow
  - Database schema setup
  - Functional test suite (FunctionalTestCase)
  - message_broker_deduplication table
  - migrations/schema.sql
problem_type: test-failure-ci
root_cause: hidden-schema-initialization-failures
symptoms:
  - Tests passing locally but failing in CI
  - Unconditional TRUNCATE on non-existent tables
  - Silent failures in schema setup (continue-on-error masking)
  - Fresh database environments missing application-managed tables
solution_type: multi-layered
  - defensive-testing-code
  - ci-validation-improvement
  - fail-fast-principles
related_issues:
  - Issue #3 - Enable auto_setup for Doctrine transports
  - PR #4 - Auto-setup refactor implementation
  - PR #7 - Phase 1 data integrity tests
related_solutions:
  - docs/solutions/database-issues/migration-schema-mismatch-ci-vs-local.md
  - docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md
---

# CI Test Failures: Hidden Schema Setup Failures in Fresh Environments

## Problem Statement

After merging PR #7 (Phase 1 functional tests) and rebasing branch `3-refactor-enable-auto-setup-doctrine-transports`, CI tests were failing with database-related errors while local tests passed. The issue manifested in two ways:

1. **Test Setup Failures**: `FunctionalTestCase::setUp()` attempted to truncate `message_broker_deduplication` table unconditionally, causing errors when the table didn't exist in fresh database environments
2. **Silent CI Failures**: GitHub Actions workflow had `continue-on-error: true` on the database schema setup step, masking schema creation failures and causing confusing downstream test failures

### Symptoms

**In CI (GitHub Actions)**:
```
Table 'messenger_test.message_broker_deduplication' doesn't exist
Table 'messenger_test.messenger_messages' doesn't exist
Tests: 34, Assertions: 189, Errors: 7
```

**Locally** (after `docker compose down -v`):
```
Table 'messenger_test.message_broker_deduplication' doesn't exist
Expected handler to be invoked 1 time(s), but was invoked 0 time(s)
Tests: 12, Assertions: 30, Errors: 1, Failures: 6
```

**Confusion Factor**: Tests passed on developer machines with existing database state from previous runs, but failed in clean CI environments.

## Root Cause Analysis

### Investigation Journey

**Step 1: Initial Symptom**
Tests failing after rebase with error:
```
Doctrine\DBAL\Exception\TableNotFoundException:
Table 'messenger_test.message_broker_deduplication' doesn't exist
```

Error occurred during test `setUp()` when calling:
```php
$connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');
```

**Step 2: Architecture Context**
The package uses a **3-table architecture** with different management strategies:

| Table | Management Strategy | Creation Method |
|-------|-------------------|-----------------|
| `messenger_outbox` | Auto-managed (Symfony) | First worker run (auto_setup: true) |
| `messenger_messages` | Auto-managed (Symfony) | First use (auto_setup: true) |
| `message_broker_deduplication` | Application-managed | Manual migration (migrations/schema.sql) |

**Step 3: Test Code Analysis**

Found inconsistent table handling in `FunctionalTestCase.php`:

```php
// ❌ BEFORE FIX - Inconsistent approach
private function cleanDatabase(): void
{
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');

    // Unconditional truncate - FAILS if table doesn't exist
    $connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');

    // Conditional checks for auto-managed tables - SAFE
    $schemaManager = $connection->createSchemaManager();
    if ($schemaManager->tablesExist(['messenger_outbox'])) {
        $connection->executeStatement('TRUNCATE TABLE messenger_outbox');
    }
    if ($schemaManager->tablesExist(['messenger_messages'])) {
        $connection->executeStatement('TRUNCATE TABLE messenger_messages');
    }
}
```

**Problem**: Deduplication table assumed to always exist, but messenger tables checked existence first.

**Step 4: CI Workflow Analysis**

Examined `.github/workflows/tests.yml`:

```yaml
# ❌ BEFORE FIX - Silent failure masking
- name: Setup database schema
  run: |
    mysql -h 127.0.0.1 -P 3308 -u messenger -pmessenger messenger_test < migrations/schema.sql
  continue-on-error: true  # Silently ignores failures!
```

The `continue-on-error: true` flag masked schema creation failures, preventing early detection.

**Step 5: Root Cause Identified**

Two compounding issues:

1. **Inconsistent table existence handling**: Application-managed table (deduplication) not checked before truncation, while auto-managed tables (messenger) were checked
2. **Silent CI failures**: Schema setup failures hidden by `continue-on-error`, surfacing as cryptic test failures downstream

**Contributing Factor**: Recent architecture change (commit `e9e5417`) enabled auto_setup for messenger tables, changing from manual to lazy creation. This exposed the inconsistent table handling in tests.

## Solution Implementation

### Fix 1: Consistent Table Existence Checks

**File**: `tests/Functional/FunctionalTestCase.php`
**Commit**: `25f1449`

Added table existence check for deduplication table, matching messenger table handling:

```php
private function cleanDatabase(): void
{
    /** @var Connection $connection */
    $connection = $this->getContainer()->get('doctrine.dbal.default_connection');

    // SAFETY CHECK: Prevent accidental production database operations
    $params = $connection->getParams();
    if (!str_contains($params['dbname'] ?? '', '_test')) {
        throw new \RuntimeException(
            'Safety check failed: Database must contain "_test" in name. ' .
            'Got: ' . ($params['dbname'] ?? 'unknown')
        );
    }

    // Truncate tables - ALL tables may not exist yet, check before truncating
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');

    $schemaManager = $connection->createSchemaManager();

    // ✅ Application-managed deduplication table
    if ($schemaManager->tablesExist(['message_broker_deduplication'])) {
        $connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');
    }

    // ✅ Auto-managed messenger tables (created by Symfony on first use)
    if ($schemaManager->tablesExist(['messenger_outbox'])) {
        $connection->executeStatement('TRUNCATE TABLE messenger_outbox');
    }
    if ($schemaManager->tablesExist(['messenger_messages'])) {
        $connection->executeStatement('TRUNCATE TABLE messenger_messages');
    }

    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
}
```

**Benefits**:
- ✅ Works on fresh databases (table doesn't exist yet)
- ✅ Works after migration applied (table exists and is truncated)
- ✅ Consistent approach for all tables
- ✅ Self-documenting with clear comments

### Fix 2: CI Workflow Validation

**File**: `.github/workflows/tests.yml`
**Commit**: `75f5b8c`

Removed `continue-on-error` and added verification:

```yaml
- name: Setup database schema
  run: |
    echo "Setting up application-managed database tables..."
    # Only create deduplication table (messenger tables are auto-managed by Symfony)
    mysql -h 127.0.0.1 -P 3308 -u messenger -pmessenger messenger_test < migrations/schema.sql
    echo "Schema setup complete!"

    # ✅ VERIFICATION: Confirm table actually exists
    mysql -h 127.0.0.1 -P 3308 -u messenger -pmessenger messenger_test \
      -e "SHOW TABLES;" | grep message_broker_deduplication
```

**Changes**:
1. **Removed** `continue-on-error: true` - failures now fail the build immediately
2. **Added** echo statements for visibility during execution
3. **Added** verification step to confirm table creation succeeded

**Benefits**:
- ✅ Fails fast on schema creation errors
- ✅ Explicit verification of critical table
- ✅ Clear logging for debugging
- ✅ No silent failures

## Verification

### Local Testing (Fresh Environment)

Simulated CI environment locally:

```bash
# Step 1: Nuclear reset - destroy all containers and volumes
docker compose down -v

# Step 2: Start fresh environment
docker compose up -d

# Step 3: Wait for services
docker compose ps  # Verify all services healthy

# Step 4: Apply schema migration
docker compose exec mysql mysql -u messenger -pmessenger messenger_test \
  < migrations/schema.sql

# Step 5: Verify table creation
docker compose exec mysql mysql -u messenger -pmessenger messenger_test \
  -e "SHOW TABLES;"
# Output: message_broker_deduplication, messenger_outbox

# Step 6: Run all tests
docker compose run --rm php vendor/bin/phpunit --testdox

# Result: ✅ All 25 tests passing
```

### CI Verification

After pushing commits `25f1449` and `75f5b8c`:

1. GitHub Actions workflow started
2. MySQL service initialized with fresh `messenger_test` database
3. Schema setup step executed successfully
4. Verification step confirmed table exists: `grep message_broker_deduplication`
5. All tests passed (25 tests, 152 assertions)

### Test Scenarios Covered

- ✅ **Fresh environment** (no tables): Tests pass, auto-setup creates messenger tables
- ✅ **Partial schema** (only deduplication): Tests pass, truncates existing, creates messenger tables
- ✅ **Complete schema** (all tables): Tests pass, truncates all tables
- ✅ **CI environment** (clean slate every run): Tests pass consistently

## Prevention Strategies

### 1. Fail-Fast Philosophy for Infrastructure

**Rule**: NEVER use `continue-on-error` for infrastructure setup steps (database, migrations, schema).

**Rationale**: Infrastructure failures should fail the entire CI run immediately, not cause confusing downstream test failures.

**When to use `continue-on-error`**:
- ✅ Optional reporting steps (test summaries, coverage uploads)
- ✅ Non-critical cleanup operations
- ❌ NEVER for database setup, migrations, service health checks

### 2. Verification After Critical Operations

Add explicit verification after every critical infrastructure step:

```yaml
# Good pattern
- name: Setup critical infrastructure
  run: |
    # Do the setup
    some-setup-command

    # VERIFY it actually worked
    verify-command || exit 1
```

### 3. Local/CI Environment Parity

Ensure `compose.yaml` matches CI service definitions:
- Same database versions (not `latest`)
- Same credentials
- Same port mappings
- Same healthcheck commands

### 4. Fresh Environment Testing Checklist

Before pushing changes affecting database/CI:

```bash
# 1. Clean slate
docker compose down -v

# 2. Start fresh
docker compose up -d

# 3. Apply migrations
docker compose exec mysql mysql -u messenger -pmessenger messenger_test \
  < migrations/schema.sql

# 4. Run tests
docker compose run --rm php vendor/bin/phpunit

# 5. Verify success
echo $?  # Should be 0
```

### 5. Database Safety Checks in Tests

Always verify database name before destructive operations:

```php
$params = $connection->getParams();
if (!str_contains($params['dbname'] ?? '', '_test')) {
    throw new \RuntimeException('Safety check failed: Not a test database');
}
```

### 6. Table Management Documentation

Document table management strategy clearly:

| Table | Management | Creation | Cleanup |
|-------|-----------|----------|---------|
| messenger_outbox | Auto (Symfony) | First worker run | Conditional truncate |
| messenger_messages | Auto (Symfony) | First use | Conditional truncate |
| message_broker_deduplication | Application | Manual migration | Conditional truncate |

## Key Learnings

### 1. Test Environment Parity
**Issue**: Local environment had tables from prior runs, masking missing table issues
**Lesson**: Always test with `docker compose down -v` to simulate fresh CI environment
**Prevention**: Document fresh environment testing in contribution guidelines

### 2. Fail-Fast Principle
**Issue**: `continue-on-error: true` silently masked schema failures
**Lesson**: Only use `continue-on-error` when failures are truly acceptable
**Prevention**: Add verification steps for critical setup operations

### 3. Consistent Abstractions
**Issue**: Mixing unconditional and conditional table operations created confusion
**Lesson**: Apply the same pattern (existence checks) to all tables
**Prevention**: Code review checklist for database operation consistency

### 4. Self-Documenting Code
**Issue**: Comments didn't explain auto-managed vs application-managed distinction
**Lesson**: Comments should explain **why**, not just **what**
**Solution**: Added clear section comments explaining table management strategies

### 5. Architecture Change Impact
**Issue**: Auto-setup refactor had edge cases in test code
**Lesson**: When changing table management strategy, audit **all** code touching those tables
**Prevention**: Architecture change checklist covering production code, tests, CI, docs

## Related Documentation

### Solution Documents
- **[docs/solutions/database-issues/migration-schema-mismatch-ci-vs-local.md](../database-issues/migration-schema-mismatch-ci-vs-local.md)**
  Previous schema mismatch issue where `messenger_outbox.id` was incorrectly `BINARY(16)` instead of `BIGINT AUTO_INCREMENT`

- **[docs/solutions/test-failures/deduplication-middleware-not-running-in-tests.md](../test-failures/deduplication-middleware-not-running-in-tests.md)**
  DeduplicationMiddleware not executing in tests due to missing explicit bus configuration

### Issues & Pull Requests
- **Issue #3**: Enable auto_setup for Doctrine Messenger transports
- **PR #4**: Auto-setup refactor implementation (in progress)
- **PR #7**: Phase 1 data integrity tests (merged)

### Architecture Documentation
- **[docs/database-schema.md](../../database-schema.md)**: 3-table architecture, management strategies
- **[docs/outbox-pattern.md](../../outbox-pattern.md)**: Outbox pattern implementation
- **[docs/inbox-deduplication.md](../../inbox-deduplication.md)**: Deduplication middleware

## Files Modified

### 1. Test Infrastructure
**File**: `tests/Functional/FunctionalTestCase.php`
**Lines**: 70-87
**Change**: Added table existence check before truncating deduplication table

### 2. CI Workflow
**File**: `.github/workflows/tests.yml`
**Lines**: 94-101
**Changes**:
- Removed `continue-on-error: true`
- Added logging statements
- Added table existence verification

### 3. Schema Migration
**File**: `migrations/schema.sql`
**Contents**: Only deduplication table (messenger tables auto-managed)

## Commit History

**Commit 25f1449**: `fix(tests): check if deduplication table exists before truncating`
- Added existence check for deduplication table in `cleanDatabase()`
- Made table handling consistent with auto-managed messenger tables
- Allows tests to work on both fresh and existing databases

**Commit 75f5b8c**: `fix(ci): ensure database schema setup failures are visible`
- Removed `continue-on-error: true` from schema setup step
- Added verification to confirm table creation
- Added descriptive logging for debugging
- Ensures CI fails fast on schema issues

## Quick Reference Commands

```bash
# Verify database state
docker compose exec mysql mysql -u messenger -pmessenger messenger_test \
  -e "SHOW TABLES;"

# Fresh environment test
docker compose down -v && docker compose up -d && \
  docker compose exec mysql mysql -u messenger -pmessenger messenger_test \
  < migrations/schema.sql && \
  docker compose run --rm php vendor/bin/phpunit --testdox

# Check specific test
docker compose run --rm php vendor/bin/phpunit \
  tests/Functional/OutboxFlowTest.php::testEventIsStoredInOutboxDatabase

# Verify CI workflow
git push origin 3-refactor-enable-auto-setup-doctrine-transports
# Check GitHub Actions logs for schema setup verification
```

## Summary

**Problem**: CI tests failing due to missing database schema, masked by `continue-on-error` in setup step.

**Root Cause**:
1. Test setup assumed deduplication table always exists (unconditional truncate)
2. CI workflow hid schema setup failures with `continue-on-error: true`

**Solution**:
1. Made table cleanup consistent - check existence before truncating all tables
2. Removed error masking and added explicit verification in CI workflow

**Impact**: Tests now work reliably in fresh CI environments and local clean-slate testing.

**Prevention**: Use fail-fast approach for infrastructure, verify critical operations, test with fresh environments before pushing.
